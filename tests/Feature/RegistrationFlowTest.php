<?php

namespace Tests\Feature;

use Apps\Tournaments\Repositories\AuditLogRepository;
use Apps\Tournaments\Repositories\TournamentRepository;
use Apps\Tournaments\Services\AuditLogService;
use Apps\Tournaments\Services\RegistrationService;
use Apps\Tournaments\Services\TournamentService;
use Tests\SqliteTestCase;
use PDO;

/**
 * Flujo de inscripción (R-REG-01, D-F0-6) sobre SQLite :memory: con
 * migraciones reales: reinscripción tras rechazo/cancelación reutiliza la
 * fila (sin 500 por UNIQUE), aceptar es transaccional y el organizador puede
 * cancelar una aceptada liberando cupo + notificando.
 */
class RegistrationFlowTest extends SqliteTestCase
{
    private static TournamentService $tournaments;
    private static RegistrationService $registrations;

    protected static function migrarBD(): bool
    {
        return true;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $audit = new AuditLogService(new AuditLogRepository());
        self::$tournaments = new TournamentService(new TournamentRepository(), $audit);
        self::$registrations = new RegistrationService(self::$tournaments, $audit);
    }

    private function createUser(string $username, string $email): int
    {
        $stmt = self::$pdo->prepare("INSERT INTO users (username, email, password, status, email_verified_at) VALUES (?, ?, ?, 'active', ?)");
        $stmt->execute([$username, $email, password_hash('secret', PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        return (int) self::$pdo->lastInsertId();
    }

    private function createTeam(string $name): int
    {
        $stmt = self::$pdo->prepare('INSERT INTO teams (name, created_at, updated_at) VALUES (?, ?, ?)');
        $stmt->execute([$name, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        return (int) self::$pdo->lastInsertId();
    }

    private function createPlayer(?int $userId, string $name): int
    {
        $stmt = self::$pdo->prepare('INSERT INTO players (user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $name, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        return (int) self::$pdo->lastInsertId();
    }

    private function createTournament(int $organizerId, array $data = []): array
    {
        return self::$tournaments->create($organizerId, array_merge([
            'title' => 'Copa Inscripciones',
            'format' => 'eliminacion-directa',
            'max_participants' => 8,
            'visibility' => 'publico',
        ], $data));
    }

    private function registrationRow(int $registrationId): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM tournament_registrations WHERE id = ?');
        $stmt->execute([$registrationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function participantCount(int $tournamentId): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        return (int) $stmt->fetchColumn();
    }

    private function participantIdOf(int $tournamentId, int $teamId): int
    {
        $stmt = self::$pdo->prepare('SELECT id FROM tournament_participants WHERE tournament_id = ? AND team_id = ?');
        $stmt->execute([$tournamentId, $teamId]);
        return (int) $stmt->fetchColumn();
    }

    private function insertarPartido(int $tournamentId, ?int $a, ?int $b, string $status, ?int $winner = null): int
    {
        $stmt = self::$pdo->prepare(
            'INSERT INTO matches (tournament_id, round_number, match_number, participant_a_id, participant_b_id, winner_participant_id, status, created_at, updated_at)
             VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tournamentId,
            random_int(1000, 999999),
            $a,
            $b,
            $winner,
            $status,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);
        return (int) self::$pdo->lastInsertId();
    }

    public function test_rejected_registration_can_reapply_and_be_accepted(): void
    {
        $organizer = $this->createUser('org_reg1', 'org.reg1@test.local');
        $applicant = $this->createUser('app_reg1', 'app.reg1@test.local');
        $teamId = $this->createTeam('Reinscritos FC');

        $tournament = $this->createTournament($organizer, ['title' => 'Copa Reinscripción', 'max_participants' => 4]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $r1 = self::$registrations->apply($applicant, $tournamentId, ['team_id' => $teamId]);
        self::$registrations->decide($organizer, $tournamentId, (int) $r1['id'], ['action' => 'rejected']);

        $rejected = $this->registrationRow((int) $r1['id']);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertNotNull($rejected['decided_by']);

        // Reinscripción: reutiliza la fila (mismo id) y vuelve a pending limpio.
        $r2 = self::$registrations->apply($applicant, $tournamentId, ['team_id' => $teamId]);
        $this->assertSame((int) $r1['id'], (int) $r2['id'], 'La reinscripción reutiliza la fila previa');
        $this->assertSame('pending', $r2['status']);
        $this->assertNull($r2['decided_by']);
        $this->assertNull($r2['decided_at']);
        $this->assertNull($r2['message']);

        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = ? AND team_id = ?');
        $stmt->execute([$tournamentId, $teamId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'No se inserta una segunda fila (UNIQUE)');

        // Aceptar la reinscripción funciona (sin 500).
        $accepted = self::$registrations->decide($organizer, $tournamentId, (int) $r2['id'], ['action' => 'accepted']);
        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame(1, $this->participantCount($tournamentId));

        // Mientras sigue pending/accepted la doble solicitud se rechaza igual que antes.
        try {
            self::$registrations->apply($applicant, $tournamentId, ['team_id' => $teamId]);
            $this->fail('Una segunda solicitud activa debe rechazarse');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('ya tiene', $e->getMessage());
        }
    }

    public function test_reapply_after_cancellation_reuses_row(): void
    {
        $organizer = $this->createUser('org_reg2', 'org.reg2@test.local');
        $applicant = $this->createUser('app_reg2', 'app.reg2@test.local');
        $teamId = $this->createTeam('Cancelados FC');

        $tournament = $this->createTournament($organizer, ['title' => 'Copa Cancelación', 'max_participants' => 4]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $r1 = self::$registrations->apply($applicant, $tournamentId, ['team_id' => $teamId]);
        $cancelled = self::$registrations->cancel($applicant, $tournamentId, (int) $r1['id']);
        $this->assertSame('cancelled', $cancelled['status']);

        // Reaplicar tras cancelación funciona y reutiliza la misma fila.
        $r2 = self::$registrations->apply($applicant, $tournamentId, ['team_id' => $teamId]);
        $this->assertSame((int) $r1['id'], (int) $r2['id']);
        $this->assertSame('pending', $r2['status']);
        $this->assertSame(0, $this->participantCount($tournamentId));
    }

    public function test_organizer_cancel_accepted_frees_slot_and_notifies(): void
    {
        $organizer = $this->createUser('org_reg3', 'org.reg3@test.local');
        $applicant1 = $this->createUser('app_reg3a', 'app.reg3a@test.local');
        $applicant2 = $this->createUser('app_reg3b', 'app.reg3b@test.local');
        $applicant3 = $this->createUser('app_reg3c', 'app.reg3c@test.local');
        $applicant4 = $this->createUser('app_reg3d', 'app.reg3d@test.local');
        $team1 = $this->createTeam('Cupo Uno');
        $team2 = $this->createTeam('Cupo Dos');
        $team3 = $this->createTeam('Cupo Tres');
        $team4 = $this->createTeam('Cupo Cuatro');

        $tournament = $this->createTournament($organizer, ['title' => 'Copa Cupo', 'format' => 'round-robin', 'max_participants' => 3]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $r1 = self::$registrations->apply($applicant1, $tournamentId, ['team_id' => $team1]);
        $r2 = self::$registrations->apply($applicant2, $tournamentId, ['team_id' => $team2]);
        $r3 = self::$registrations->apply($applicant3, $tournamentId, ['team_id' => $team3]);
        self::$registrations->decide($organizer, $tournamentId, (int) $r1['id'], ['action' => 'accepted']);
        self::$registrations->decide($organizer, $tournamentId, (int) $r2['id'], ['action' => 'accepted']);
        self::$registrations->decide($organizer, $tournamentId, (int) $r3['id'], ['action' => 'accepted']);
        $this->assertSame(3, $this->participantCount($tournamentId));

        // El organizador cancela una aceptada: se borra el participante y se notifica.
        $cancelled = self::$registrations->cancel($organizer, $tournamentId, (int) $r1['id']);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame(2, $this->participantCount($tournamentId), 'La cancelación libera el cupo');

        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'registro.cancelado'");
        $stmt->execute([$applicant1]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'El solicitante recibe la notificación de cancelación');

        // El cupo liberado permite aceptar a un cuarto equipo.
        $r4 = self::$registrations->apply($applicant4, $tournamentId, ['team_id' => $team4]);
        $accepted = self::$registrations->decide($organizer, $tournamentId, (int) $r4['id'], ['action' => 'accepted']);
        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame(3, $this->participantCount($tournamentId));
    }

    /**
     * Cancelar una aceptada limpia el calendario del participante: sin esto la
     * FK (ON DELETE SET NULL) dejaba cruces huérfanos «Bye vs Bye».
     */
    public function test_organizer_cancel_accepted_purges_participant_schedule(): void
    {
        $organizer = $this->createUser('org_cal1', 'org.cal1@test.local');
        $applicant1 = $this->createUser('app_cal1a', 'app.cal1a@test.local');
        $applicant2 = $this->createUser('app_cal1b', 'app.cal1b@test.local');
        $applicant3 = $this->createUser('app_cal1c', 'app.cal1c@test.local');
        $team1 = $this->createTeam('Sale FC');
        $team2 = $this->createTeam('Queda FC');
        $team3 = $this->createTeam('Intacto FC');

        $tournament = $this->createTournament($organizer, ['title' => 'Copa Calendario', 'max_participants' => 4]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $r1 = self::$registrations->apply($applicant1, $tournamentId, ['team_id' => $team1]);
        $r2 = self::$registrations->apply($applicant2, $tournamentId, ['team_id' => $team2]);
        $r3 = self::$registrations->apply($applicant3, $tournamentId, ['team_id' => $team3]);
        self::$registrations->decide($organizer, $tournamentId, (int) $r1['id'], ['action' => 'accepted']);
        self::$registrations->decide($organizer, $tournamentId, (int) $r2['id'], ['action' => 'accepted']);
        self::$registrations->decide($organizer, $tournamentId, (int) $r3['id'], ['action' => 'accepted']);

        $p1 = $this->participantIdOf($tournamentId, $team1);
        $p2 = $this->participantIdOf($tournamentId, $team2);
        $p3 = $this->participantIdOf($tournamentId, $team3);

        $sinResultado = $this->insertarPartido($tournamentId, $p1, $p2, 'pending');
        $conResultado = $this->insertarPartido($tournamentId, $p2, $p1, 'completed', $p1);
        $deTerceros = $this->insertarPartido($tournamentId, $p2, $p3, 'pending');

        self::$registrations->cancel($organizer, $tournamentId, (int) $r1['id']);

        // El partido sin resultado del participante que sale se elimina.
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM matches WHERE id = ?');
        $stmt->execute([$sinResultado]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'El partido sin resultado se borra con el participante');

        // El partido con resultado se conserva como historial cancelado.
        $stmt = self::$pdo->prepare('SELECT status, winner_participant_id FROM matches WHERE id = ?');
        $stmt->execute([$conResultado]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('cancelled', $row['status']);
        $this->assertNull($row['winner_participant_id']);

        // Los partidos de otros participantes no se tocan.
        $stmt = self::$pdo->prepare('SELECT status, participant_a_id, participant_b_id FROM matches WHERE id = ?');
        $stmt->execute([$deTerceros]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertSame($p2, (int) $row['participant_a_id']);
        $this->assertSame($p3, (int) $row['participant_b_id']);

        // No quedan cruces huérfanos (los dos lados nulos) en el torneo.
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND participant_a_id IS NULL AND participant_b_id IS NULL');
        $stmt->execute([$tournamentId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Sin partidos «Bye vs Bye»');
    }

    public function test_applicant_cannot_cancel_accepted_registration(): void
    {
        $organizer = $this->createUser('org_reg4', 'org.reg4@test.local');
        $applicant = $this->createUser('app_reg4', 'app.reg4@test.local');
        $teamId = $this->createTeam('Intocable FC');

        $tournament = $this->createTournament($organizer, ['title' => 'Copa Bloqueada', 'max_participants' => 4]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $r = self::$registrations->apply($applicant, $tournamentId, ['team_id' => $teamId]);
        self::$registrations->decide($organizer, $tournamentId, (int) $r['id'], ['action' => 'accepted']);

        try {
            self::$registrations->cancel($applicant, $tournamentId, (int) $r['id']);
            $this->fail('El solicitante no puede cancelar una inscripción aceptada');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // Ni se borra el participante ni cambia el estado.
        $this->assertSame(1, $this->participantCount($tournamentId));
        $this->assertSame('accepted', $this->registrationRow((int) $r['id'])['status']);
    }

    public function test_organizer_cancel_pending_keeps_old_semantics(): void
    {
        $organizer = $this->createUser('org_reg5', 'org.reg5@test.local');
        $applicant = $this->createUser('app_reg5', 'app.reg5@test.local');
        $teamId = $this->createTeam('Pendiente FC');

        $tournament = $this->createTournament($organizer, ['title' => 'Copa Pendiente', 'max_participants' => 4]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $r = self::$registrations->apply($applicant, $tournamentId, ['team_id' => $teamId]);
        $cancelled = self::$registrations->cancel($organizer, $tournamentId, (int) $r['id']);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame(0, $this->participantCount($tournamentId));

        try {
            self::$registrations->cancel($organizer, $tournamentId, (int) $r['id']);
            $this->fail('Una solicitud cancelada no se vuelve a cancelar');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    public function test_linked_player_cancels_own_pending_registration(): void
    {
        $organizer = $this->createUser('org_reg6', 'org.reg6@test.local');
        $applicant = $this->createUser('app_reg6', 'app.reg6@test.local');
        $playerUser = $this->createUser('ply_reg6', 'ply.reg6@test.local');
        $playerId = $this->createPlayer($playerUser, 'Jugador Vinculado');

        $tournament = $this->createTournament($organizer, [
            'title' => 'Copa Individual',
            'max_participants' => 4,
            'is_individual' => true,
        ]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $r = self::$registrations->apply($applicant, $tournamentId, ['player_id' => $playerId]);
        $this->assertSame('pending', $r['status']);

        // El jugador vinculado (players.user_id) cancela su propia pending.
        $cancelled = self::$registrations->cancel($playerUser, $tournamentId, (int) $r['id']);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame('cancelled', $this->registrationRow((int) $r['id'])['status']);
    }

    public function test_unrelated_user_cannot_cancel_pending_registration(): void
    {
        $organizer = $this->createUser('org_reg7', 'org.reg7@test.local');
        $applicant = $this->createUser('app_reg7', 'app.reg7@test.local');
        $unrelated = $this->createUser('other_reg7', 'other.reg7@test.local');
        $teamId = $this->createTeam('Ajena FC');

        $tournament = $this->createTournament($organizer, ['title' => 'Copa Ajena', 'max_participants' => 4]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $r = self::$registrations->apply($applicant, $tournamentId, ['team_id' => $teamId]);

        try {
            self::$registrations->cancel($unrelated, $tournamentId, (int) $r['id']);
            $this->fail('Un usuario ajeno no puede cancelar una solicitud de otro');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }

        $this->assertSame('pending', $this->registrationRow((int) $r['id'])['status']);
    }

    public function test_linked_player_cannot_cancel_accepted_registration(): void
    {
        $organizer = $this->createUser('org_reg8', 'org.reg8@test.local');
        $applicant = $this->createUser('app_reg8', 'app.reg8@test.local');
        $playerUser = $this->createUser('ply_reg8', 'ply.reg8@test.local');
        $playerId = $this->createPlayer($playerUser, 'Jugador Aceptado');

        $tournament = $this->createTournament($organizer, [
            'title' => 'Copa Individual Aceptada',
            'max_participants' => 4,
            'is_individual' => true,
        ]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $r = self::$registrations->apply($applicant, $tournamentId, ['player_id' => $playerId]);
        self::$registrations->decide($organizer, $tournamentId, (int) $r['id'], ['action' => 'accepted']);

        try {
            self::$registrations->cancel($playerUser, $tournamentId, (int) $r['id']);
            $this->fail('El jugador vinculado no puede cancelar una inscripción aceptada');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }

        $this->assertSame('accepted', $this->registrationRow((int) $r['id'])['status']);
        $this->assertSame(1, $this->participantCount($tournamentId));
    }
}
