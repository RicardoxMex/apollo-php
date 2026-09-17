<?php

namespace Tests\Feature;

use Apps\Tournaments\Repositories\AuditLogRepository;
use Apps\Tournaments\Repositories\TournamentRepository;
use Apps\Tournaments\Services\AuditLogService;
use Apps\Tournaments\Services\DrawService;
use Apps\Tournaments\Services\RegistrationService;
use Apps\Tournaments\Services\TournamentService;
use Tests\SqliteTestCase;
use PDO;

/**
 * Añadir participantes a un sorteo ya generado (draw/participants):
 *  - bracket: rellena cupos bye libres sin tocar los cruces existentes; sin
 *    cupos, 409 pidiendo limpiar/regenerar.
 *  - grupos: reparte al grupo con menos miembros y crea SOLO los partidos que
 *    faltan (jornada nueva al final); los partidos previos quedan intactos.
 *  - idempotente: repetir un id ya sorteado no cambia nada.
 */
class DrawParticipantsTest extends SqliteTestCase
{
    private static TournamentService $tournaments;
    private static RegistrationService $registrations;
    private static DrawService $draws;

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
        self::$draws = new DrawService($audit);
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

    /** Inscribe y acepta un equipo nuevo; devuelve el id de participante. */
    private function aceptarEquipo(int $organizerId, int $tournamentId): int
    {
        $teamId = $this->createTeam('Equipo ' . uniqid());
        $reg = self::$registrations->apply($organizerId, $tournamentId, ['team_id' => $teamId]);
        self::$registrations->decide($organizerId, $tournamentId, (int) $reg['id'], ['action' => 'accepted']);

        $stmt = self::$pdo->prepare('SELECT id FROM tournament_participants WHERE tournament_id = ? AND team_id = ?');
        $stmt->execute([$tournamentId, $teamId]);
        return (int) $stmt->fetchColumn();
    }

    /** Torneo sin publicar + N participantes aceptados (el organizador inscribe en draft). */
    private function torneoConParticipantes(int $organizerId, int $total, string $formato): array
    {
        $tournament = self::$tournaments->create($organizerId, [
            'title' => 'Copa Sorteo ' . uniqid(),
            'format' => $formato,
            'max_participants' => 16,
            'visibility' => 'publico',
        ]);
        $tournamentId = (int) $tournament['id'];

        $ids = [];
        for ($i = 0; $i < $total; $i++) {
            $ids[] = $this->aceptarEquipo($organizerId, $tournamentId);
        }
        return [$tournamentId, $ids];
    }

    /** Snapshot id => [a, b] de los partidos oficiales del torneo. */
    private function snapshotMatches(int $tournamentId): array
    {
        $stmt = self::$pdo->prepare('SELECT id, participant_a_id, participant_b_id, round_number FROM matches WHERE tournament_id = ? ORDER BY id ASC');
        $stmt->execute([$tournamentId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $out[(int) $m['id']] = [
                'a' => $m['participant_a_id'] !== null ? (int) $m['participant_a_id'] : null,
                'b' => $m['participant_b_id'] !== null ? (int) $m['participant_b_id'] : null,
                'round' => (int) $m['round_number'],
            ];
        }
        return $out;
    }

    public function test_bracket_fills_bye_without_touching_existing_matches(): void
    {
        $organizer = $this->createUser('org_draw_1', 'org.draw1@test.local');
        [$tournamentId, $ids] = $this->torneoConParticipantes($organizer, 3, 'eliminacion-directa');

        $draw = self::$draws->generate($organizer, $tournamentId, ['type' => 'bracket']);
        $this->assertCount(2, $draw['rounds'][0]['matches'], 'Ronda 1: un bye y un cruce');

        $antes = $this->snapshotMatches($tournamentId);
        $this->assertCount(3, $antes, '3 participantes → 3 partidos (bye + cruce + final)');

        $cruce = null;
        foreach ($draw['rounds'][0]['matches'] as $m) {
            if ($m['participant_a_id'] !== null && $m['participant_b_id'] !== null) {
                $cruce = $m;
            }
        }
        $this->assertNotNull($cruce);

        $nuevoId = $this->aceptarEquipo($organizer, $tournamentId);
        $despues = self::$draws->addParticipants($organizer, $tournamentId, [$nuevoId]);

        // El bye quedó completo con el nuevo participante.
        $bye = $despues['rounds'][0]['matches'][0];
        $this->assertNotNull($bye['participant_a_id']);
        $this->assertSame($nuevoId, $bye['participant_b_id'], 'El nuevo participante ocupa el cupo bye');

        // El cruce existente no cambió.
        $cruceDespues = $despues['rounds'][0]['matches'][1];
        $this->assertSame($cruce['participant_a_id'], $cruceDespues['participant_a_id']);
        $this->assertSame($cruce['participant_b_id'], $cruceDespues['participant_b_id']);

        // Ni los partidos oficiales previos (mismos ids, mismos lados).
        $snapshot = $this->snapshotMatches($tournamentId);
        $this->assertCount(3, $snapshot, 'Rellenar el bye no crea partidos nuevos');
        foreach ($antes as $matchId => $previo) {
            $this->assertArrayHasKey($matchId, $snapshot, 'El partido existente sigue vivo');
            if ($matchId !== (int) $bye['match_id']) {
                $this->assertSame($previo['a'], $snapshot[$matchId]['a']);
                $this->assertSame($previo['b'], $snapshot[$matchId]['b']);
            }
        }
        $this->assertSame($nuevoId, $snapshot[(int) $bye['match_id']]['b'], 'El match oficial del bye refleja al nuevo');
    }

    public function test_bracket_without_free_byes_rejects_and_keeps_draw(): void
    {
        $organizer = $this->createUser('org_draw_2', 'org.draw2@test.local');
        [$tournamentId] = $this->torneoConParticipantes($organizer, 4, 'eliminacion-directa');

        self::$draws->generate($organizer, $tournamentId, ['type' => 'bracket']);
        $antes = $this->snapshotMatches($tournamentId);

        $nuevoId = $this->aceptarEquipo($organizer, $tournamentId);
        try {
            self::$draws->addParticipants($organizer, $tournamentId, [$nuevoId]);
            $this->fail('Un cuadro completo sin byes debe rechazar la incorporación');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('cupos libres', $e->getMessage());
        }

        $this->assertSame($antes, $this->snapshotMatches($tournamentId), 'El sorteo queda intacto');
    }

    public function test_groups_append_to_smallest_group_and_create_only_missing_matches(): void
    {
        $organizer = $this->createUser('org_draw_3', 'org.draw3@test.local');
        [$tournamentId, $ids] = $this->torneoConParticipantes($organizer, 4, 'grupos');

        $draw = self::$draws->generate($organizer, $tournamentId, ['type' => 'groups', 'num_groups' => 2]);
        $this->assertCount(2, $draw['groups']);
        foreach ($draw['groups'] as $g) {
            $this->assertCount(2, $g['participants'], 'Grupos balanceados 2+2');
        }

        $antes = $this->snapshotMatches($tournamentId);
        $this->assertCount(2, $antes, 'Un partido por grupo');

        $nuevoId = $this->aceptarEquipo($organizer, $tournamentId);
        $despues = self::$draws->addParticipants($organizer, $tournamentId, [$nuevoId]);

        // El nuevo entra al primer grupo (empate a 2 → Grupo A).
        $grupoA = $despues['groups'][0];
        $miembrosA = array_map(fn ($p) => (int) $p['tournament_participant_id'], $grupoA['participants']);
        $this->assertContains($nuevoId, $miembrosA);

        // Partidos previos intactos + solo los que faltan contra el grupo A (2).
        $snapshot = $this->snapshotMatches($tournamentId);
        $this->assertCount(4, $snapshot, '2 previos + 2 nuevos (vs cada miembro del grupo)');
        foreach ($antes as $matchId => $previo) {
            $this->assertArrayHasKey($matchId, $snapshot);
            $this->assertSame($previo['round'], $snapshot[$matchId]['round'], 'Los partidos previos conservan su jornada');
        }

        $nuevos = array_diff_key($snapshot, $antes);
        $this->assertCount(2, $nuevos);
        $rondasPrevias = max(array_column($antes, 'round'));
        foreach ($nuevos as $m) {
            $this->assertSame($rondasPrevias + 1, $m['round'], 'Los nuevos van a la jornada siguiente');
            $this->assertTrue($m['a'] === $nuevoId || $m['b'] === $nuevoId, 'Solo parejas del nuevo participante');
        }
    }

    public function test_add_is_idempotent_for_already_drawn_participants(): void
    {
        $organizer = $this->createUser('org_draw_4', 'org.draw4@test.local');
        [$tournamentId, $ids] = $this->torneoConParticipantes($organizer, 3, 'eliminacion-directa');

        self::$draws->generate($organizer, $tournamentId, ['type' => 'bracket']);
        $antes = $this->snapshotMatches($tournamentId);

        $despues = self::$draws->addParticipants($organizer, $tournamentId, $ids);
        $this->assertSame($antes, $this->snapshotMatches($tournamentId), 'Sin cambios al repetir participantes ya sorteados');
        $this->assertNotNull($despues['draw']);
    }
}
