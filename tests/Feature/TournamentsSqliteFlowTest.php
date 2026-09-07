<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apps\Tournaments\Repositories\AuditLogRepository;
use Apps\Tournaments\Repositories\MatchRepository;
use Apps\Tournaments\Repositories\TeamRepository;
use Apps\Tournaments\Repositories\TournamentRepository;
use Apps\Tournaments\Services\AuditLogService;
use Apps\Tournaments\Services\DrawService;
use Apps\Tournaments\Services\MatchService;
use Apps\Tournaments\Services\RegistrationService;
use Apps\Tournaments\Services\TeamService;
use Apps\Tournaments\Services\TournamentService;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Full tournament domain flow on SQLite :memory: (real migrations):
 * team → tournament (draft) → organizer direct registration → publish → public
 * request → moderation → draw (byes + advances) → live → matches with score/winner
 * → finish. Validates the services without needing MySQL.
 * Requires extension=pdo_sqlite (skipped if not loaded).
 */
class TournamentsSqliteFlowTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static TournamentService $tournaments;
    private static TeamService $teams;
    private static RegistrationService $registrations;
    private static DrawService $draws;
    private static MatchService $matches;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite no disponible');
        }

        new \Apollo\Core\Application(dirname(__DIR__, 2));
        \app('config');

        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        DatabaseManager::disconnect();

        self::$pdo = DatabaseManager::getConnection();

        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }

        $audit = new AuditLogService(new AuditLogRepository());
        self::$tournaments = new TournamentService(new TournamentRepository(), $audit);
        self::$teams = new TeamService(new TeamRepository(), $audit);
        self::$registrations = new RegistrationService(self::$tournaments, $audit);
        self::$draws = new DrawService($audit);
        self::$matches = new MatchService(new MatchRepository(), $audit);
    }

    private function createUser(string $username, string $email): int
    {
        $stmt = self::$pdo->prepare("INSERT INTO users (username, email, password, status) VALUES (?, ?, ?, 'active')");
        $stmt->execute([$username, $email, password_hash('secret', PASSWORD_DEFAULT)]);
        return (int) self::$pdo->lastInsertId();
    }

    public function test_full_tournament_flow(): void
    {
        $organizer = $this->createUser('organizador', 'org@test.local');
        $applicant1 = $this->createUser('jugador1', 'j1@test.local');

        // 1. Teams (the creator becomes captain)
        $team1 = self::$teams->create($organizer, ['name' => 'Los Pumas', 'contact' => 'a@x.com']);
        $team2 = self::$teams->create($organizer, ['name' => 'Las Águilas']);
        $team3 = self::$teams->create($organizer, ['name' => 'Los Tigres']);
        $this->assertNotNull($team1);
        $this->assertCount(1, $team1['captains']);
        $this->assertSame($organizer, (int) $team1['captains'][0]['id']);

        // 2. Draft tournament (private, with a configured stat)
        $tournament = self::$tournaments->create($organizer, [
            'title' => 'Copa Verano 2026',
            'sport' => 'Fútbol',
            'format' => 'eliminacion-directa',
            'max_participants' => 8,
            'visibility' => 'privado',
            'stats' => [['label' => 'Goles', 'type' => 'number']],
        ]);
        $this->assertSame('draft', $tournament['status']);
        $this->assertSame('privado', $tournament['visibility']);
        $this->assertSame('eliminacion-directa', $tournament['format']);
        $this->assertCount(1, $tournament['stats']);
        $tournamentId = (int) $tournament['id'];
        $statId = (int) $tournament['stats'][0]['id'];

        // 3. The public cannot register in draft; the organizer can (direct)
        try {
            self::$registrations->apply($applicant1, $tournamentId, ['team_id' => (int) $team3['id']]);
            $this->fail('El público no aplica en borrador');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no está abierto', $e->getMessage());
        }

        $r1 = self::$registrations->apply($organizer, $tournamentId, ['team_id' => (int) $team1['id']]);
        $r2 = self::$registrations->apply($organizer, $tournamentId, ['team_id' => (int) $team2['id']]);
        $this->assertSame('pending', $r1['status']);

        // 4. Double registration rejected
        try {
            self::$registrations->apply($organizer, $tournamentId, ['team_id' => (int) $team1['id']]);
            $this->fail('Debería rechazar la doble inscripción');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ya tiene', $e->getMessage());
        }

        // 5. Moderation: accept both.
        self::$registrations->decide($organizer, $tournamentId, (int) $r1['id'], ['action' => 'accepted']);
        self::$registrations->decide($organizer, $tournamentId, (int) $r2['id'], ['action' => 'accepted']);

        $participants = self::$tournaments->participants($tournamentId);
        $this->assertCount(2, $participants);
        $this->assertSame('Los Pumas', $participants[0]['display_name']);
        $this->assertSame(1, (int) $participants[0]['seed']);

        try {
            self::$registrations->list($tournamentId, $applicant1);
            $this->fail('Solo el organizador lista solicitudes');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 6. Publish → open. An external participant applies with the 3rd team → accepted.
        $published = self::$tournaments->transition($organizer, $tournamentId, 'publish');
        $this->assertSame('open', $published['status']);

        $r3 = self::$registrations->apply($applicant1, $tournamentId, ['team_id' => (int) $team3['id']]);
        $this->assertSame('pending', $r3['status']);
        self::$registrations->decide($organizer, $tournamentId, (int) $r3['id'], ['action' => 'accepted']);
        $this->assertCount(3, self::$tournaments->participants($tournamentId));

        // 7. Start without draw → rejected; generate bracket (3 teams → bye) + start
        try {
            self::$tournaments->transition($organizer, $tournamentId, 'start');
            $this->fail('Debería exigir sorteo');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sorteo', $e->getMessage());
        }

        $draw = self::$draws->generate($organizer, $tournamentId, ['type' => 'bracket']);
        $this->assertSame(1, $draw['draw']['version']);
        $this->assertCount(2, $draw['rounds']);
        $this->assertCount(2, $draw['rounds'][0]['matches']); // bye + real
        $this->assertCount(1, $draw['rounds'][1]['matches']); // final
        $this->assertNull($draw['rounds'][0]['matches'][0]['participant_b_id']); // bye

        // Official matches 1:1 (round 1: Los Pumas bye + Águilas vs Tigres; round 2: final)
        $matches = self::$matches->list($tournamentId);
        $this->assertCount(3, $matches);
        $this->assertSame('Las Águilas', $matches[1]['display_a']);
        $this->assertSame('Los Tigres', $matches[1]['display_b']);

        $live = self::$tournaments->transition($organizer, $tournamentId, 'start');
        $this->assertSame('live', $live['status']);

        // 8. Score registration with automatic advance to the next match
        // 8a. The bye (Los Pumas, best seed) advances alone to the final
        $matchBye = $matches[0];
        $this->assertNull($matchBye['participant_b_id']);
        $pById = self::$tournaments->participants($tournamentId);
        $participantePumas = (int) $pById[0]['id'];
        $match = self::$matches->update($organizer, $tournamentId, (int) $matchBye['id'], ['status' => 'completed']);
        $this->assertSame($participantePumas, (int) $match['winner_participant_id']);

        // 8b. The real one: score 3-1 → Las Águilas win (a) and advance to the final
        $matchReal = $matches[1];
        $participanteAguilas = (int) $matchReal['participant_a_id'];
        $match = self::$matches->update($organizer, $tournamentId, (int) $matchReal['id'], [
            'status' => 'completed',
            'scores' => [['stat_id' => $statId, 'a' => 3, 'b' => 1]],
        ]);
        $this->assertSame($participanteAguilas, (int) $match['winner_participant_id']);

        // The final is populated with both winners (round-trip propagation)
        $matches = self::$matches->list($tournamentId);
        $final = $matches[2];
        $this->assertSame($participantePumas, (int) $final['participant_a_id']);
        $this->assertSame($participanteAguilas, (int) $final['participant_b_id']);

        // 8c. Final: score 0-2 → champion Las Águilas; draw_match propagated
        $finalMatch = self::$matches->update($organizer, $tournamentId, (int) $final['id'], [
            'status' => 'completed',
            'scores' => [['stat_id' => $statId, 'a' => 0, 'b' => 2]],
        ]);
        $this->assertSame($participanteAguilas, (int) $finalMatch['winner_participant_id']);

        $drawFinal = self::$pdo->query('SELECT status, winner_participant_id FROM draw_matches ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $drawFinal['status']);
        $this->assertSame($participanteAguilas, (int) $drawFinal['winner_participant_id']);

        // 9. Finish → finished
        $finished = self::$tournaments->transition($organizer, $tournamentId, 'finish');
        $this->assertSame('finished', $finished['status']);

        // 10. Complete audit
        $stmt = self::$pdo->query('SELECT COUNT(*) FROM audit_logs');
        $this->assertGreaterThan(10, (int) $stmt->fetchColumn());
    }
}