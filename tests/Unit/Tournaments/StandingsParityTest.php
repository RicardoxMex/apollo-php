<?php

namespace Tests\Unit\Tournaments;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apps\Tournaments\Services\StandingsService;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Paridad de la clasificación con el frontend (M2, AC-04): el mismo dataset
 * que `tablaPosiciones` (modules/mis-torneos/lib/stats-partidos.ts) debe
 * producir exactamente la misma tabla (pts → af → pj → nombre).
 * SQLite :memory: con migraciones reales.
 */
class StandingsParityTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static StandingsService $standings;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite no disponible');
        }

        new \Apollo\Core\Application(dirname(__DIR__, 3));
        \app('config');

        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        DatabaseManager::disconnect();

        self::$pdo = DatabaseManager::getConnection();

        $files = glob(dirname(__DIR__, 3) . '/database/migrations/*.php');
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }

        self::$standings = new StandingsService();
    }

    /** Crea torneo + stat principal + N participantes (seeds 1..N). */
    private function torneoConParticipantes(string $titulo, int $n, string $formato = 'round_robin'): array
    {
        $pdo = self::$pdo;

        // Usuario organizador (FK users; username único por llamada).
        $suffix = bin2hex(random_bytes(3));
        $pdo->prepare("INSERT INTO users (username, email, password, status) VALUES ('org_stand_{$suffix}', ?, 'x', 'active')")
            ->execute([$titulo . '@stand.test']);
        $organizerId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO tournaments (organizer_id, title, slug, sport, status, format, max_participants, visibility, created_at, updated_at) VALUES (?, ?, ?, 'Fútbol', 'live', ?, ?, 'public', ?, ?)")
            ->execute([$organizerId, $titulo, strtolower(str_replace(' ', '-', $titulo)) . '-' . $suffix, $formato, $n, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $tournamentId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO tournament_stats (tournament_id, label, type, per_player) VALUES (?, 'Goles', 'number', 0)")
            ->execute([$tournamentId]);
        $statId = (int) $pdo->lastInsertId();

        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            // Solicitud aceptada (registration_id NOT NULL en el participante).
            $pdo->prepare("INSERT INTO tournament_registrations (tournament_id, applicant_id, status, created_at, updated_at) VALUES (?, ?, 'accepted', ?, ?)")
                ->execute([$tournamentId, $organizerId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $registrationId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO tournament_participants (tournament_id, registration_id, team_id, player_id, seed, created_at) VALUES (?, ?, NULL, NULL, ?, ?)")
                ->execute([$tournamentId, $registrationId, $i, date('Y-m-d H:i:s')]);
            $ids[$i] = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO teams (name, created_at, updated_at) VALUES (?, ?, ?)")
                ->execute(['Equipo ' . chr(64 + $i), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $teamId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE tournament_participants SET team_id = ? WHERE id = ?')
                ->execute([$teamId, $ids[$i]]);
        }

        return ['tournament_id' => $tournamentId, 'stat_id' => $statId, 'ids' => $ids];
    }

    private function partido(int $tournamentId, int $ronda, int $a, int $b, ?array $marcador, ?int $ganador): int
    {
        $pdo = self::$pdo;
        $pdo->prepare("INSERT INTO matches (tournament_id, round_number, participant_a_id, participant_b_id, winner_participant_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'completed', ?, ?)")
            ->execute([$tournamentId, $ronda, $a, $b, $ganador, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $matchId = (int) $pdo->lastInsertId();

        if ($marcador !== null) {
            $pdo->prepare('INSERT INTO match_scores (match_id, stat_id, score_a, score_b) VALUES (?, ?, ?, ?)')
                ->execute([$matchId, $this->statId, $marcador[0], $marcador[1]]);
        }

        return $matchId;
    }

    private int $statId = 0;

    public function test_paridad_con_tabla_posiciones_del_frontend(): void
    {
        // Dataset espejo del frontend: 4 equipos, 5 partidos (4 jugados, 1 pendiente).
        $ctx = $this->torneoConParticipantes('Copa Paridad', 4);
        $this->statId = $ctx['stat_id'];
        $ids = $ctx['ids'];

        $this->partido($ctx['tournament_id'], 1, $ids[1], $ids[2], [2, 1], $ids[1]); // A 2-1 B
        $this->partido($ctx['tournament_id'], 1, $ids[3], $ids[4], [1, 1], null);   // C 1-1 D
        $this->partido($ctx['tournament_id'], 2, $ids[1], $ids[3], [0, 0], null);   // A 0-0 C
        $this->partido($ctx['tournament_id'], 2, $ids[2], $ids[4], [3, 0], $ids[2]); // B 3-0 D
        // M5 A vs D sin resultado (no computa).

        $data = self::$standings->compute($ctx['tournament_id']);
        $rows = $data['standings'];

        $this->assertCount(4, $rows, 'Todos los participantes aparecen (incluso sin partidos)');
        $this->assertSame(['Equipo A', 'Equipo B', 'Equipo C', 'Equipo D'], array_column($rows, 'name'), 'Orden: pts → af → pj → nombre');

        $a = $rows[0];
        $this->assertSame(['pj' => 2, 'g' => 1, 'e' => 1, 'p' => 0, 'pts' => 4], [
            'pj' => $a['pj'], 'g' => $a['g'], 'e' => $a['e'], 'p' => $a['p'], 'pts' => $a['pts'],
        ]);
        $this->assertSame([2.0, 1.0], [$a['af'], $a['ec']]);

        $b = $rows[1];
        $this->assertSame(['pj' => 2, 'g' => 1, 'p' => 1, 'pts' => 3], [
            'pj' => $b['pj'], 'g' => $b['g'], 'p' => $b['p'], 'pts' => $b['pts'],
        ]);
        // B: 1 gol vs A + 3 goles vs D = 4 a favor; 2 en contra.
        $this->assertSame([4.0, 2.0], [$b['af'], $b['ec']]);

        $c = $rows[2];
        $this->assertSame(['e' => 2, 'pts' => 2, 'af' => 1.0], ['e' => $c['e'], 'pts' => $c['pts'], 'af' => $c['af']]);

        $d = $rows[3];
        $this->assertSame(['pj' => 2, 'p' => 1, 'e' => 1, 'pts' => 1], [
            'pj' => $d['pj'], 'p' => $d['p'], 'e' => $d['e'], 'pts' => $d['pts'],
        ]);
        $this->assertSame([1.0, 4.0], [$d['af'], $d['ec']]);
    }

    public function test_desempates_pts_af_pj_nombre(): void
    {
        $ctx = $this->torneoConParticipantes('Copa Desempates', 4);
        $this->statId = $ctx['stat_id'];
        $ids = $ctx['ids'];

        // Equipo A: pj2, pts3, af3 (2-1 y 1-2). Equipo B: pj1, pts3, af3 (3-0),
        // sin goles en contra del empate previo porque no jugó contra A.
        $this->partido($ctx['tournament_id'], 1, $ids[1], $ids[3], [2, 1], $ids[1]); // A 2-1 C
        $this->partido($ctx['tournament_id'], 2, $ids[1], $ids[4], [1, 2], $ids[4]); // A 1-2 D
        $this->partido($ctx['tournament_id'], 1, $ids[2], $ids[3], [3, 0], $ids[2]); // B 3-0 C

        $rows = self::$standings->compute($ctx['tournament_id'])['standings'];

        // A y B empatan en pts (3) y af (3) → pj mayor primero (A 2 > B 1);
        // D tercero por af (2); C sin puntos al final.
        $this->assertSame('Equipo A', $rows[0]['name']);
        $this->assertSame('Equipo B', $rows[1]['name']);
        $this->assertSame('Equipo D', $rows[2]['name']);
        $this->assertSame('Equipo C', $rows[3]['name']);

        $this->assertSame(3, $rows[0]['pts']);
        $this->assertSame(2, $rows[0]['pj']);
        $this->assertSame(1, $rows[1]['pj']);
        $this->assertSame(3.0, $rows[1]['af']);
    }

    public function test_ganador_sin_marcador_cuenta_como_empate_cero_a_cero(): void
    {
        $ctx = $this->torneoConParticipantes('Copa Ganador', 2);
        $this->statId = $ctx['stat_id'];
        $ids = $ctx['ids'];

        // Ganador asignado sin filas en match_scores: jugado con marcador {0,0}.
        $this->partido($ctx['tournament_id'], 1, $ids[1], $ids[2], null, $ids[1]);

        $rows = self::$standings->compute($ctx['tournament_id'])['standings'];
        $this->assertSame(1, $rows[0]['pj']);
        $this->assertSame(1, $rows[0]['e'], 'Sin marcador el resultado es empate 0-0 (espejo frontend)');
        $this->assertSame(1, $rows[1]['pj']);
    }

    public function test_grupos_desglose_por_grupo(): void
    {
        $ctx = $this->torneoConParticipantes('Copa Grupos', 4, 'groups');
        $this->statId = $ctx['stat_id'];
        $ids = $ctx['ids'];

        // Draw con 2 grupos de 2.
        self::$pdo->prepare("INSERT INTO draws (tournament_id, type, version, generated_at, created_at) VALUES (?, 'groups', 1, ?, ?)")
            ->execute([$ctx['tournament_id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $drawId = (int) self::$pdo->lastInsertId();

        foreach ([['G1', $ids[1], $ids[2]], ['G2', $ids[3], $ids[4]]] as $i => [$nombre, $pa, $pb]) {
            self::$pdo->prepare('INSERT INTO draw_groups (draw_id, name, position) VALUES (?, ?, ?)')
                ->execute([$drawId, $nombre, $i + 1]);
            $groupId = (int) self::$pdo->lastInsertId();
            foreach ([$pa, $pb] as $pid) {
                self::$pdo->prepare('INSERT INTO draw_group_participants (group_id, tournament_participant_id, position) VALUES (?, ?, ?)')
                    ->execute([$groupId, $pid, 1]);
            }
        }

        $this->partido($ctx['tournament_id'], 1, $ids[1], $ids[2], [2, 1], $ids[1]); // dentro de G1
        $this->partido($ctx['tournament_id'], 1, $ids[3], $ids[4], [1, 1], null);   // dentro de G2

        $data = self::$standings->compute($ctx['tournament_id']);
        $this->assertCount(2, $data['groups'], 'Desglose por grupo presente');

        $g1 = $data['groups'][0];
        $this->assertSame('G1', $g1['name']);
        $this->assertSame(3, $g1['standings'][0]['pts'], 'Victoria en el grupo 1');
        $g2 = $data['groups'][1];
        $this->assertSame(1, $g2['standings'][0]['pts'], 'Empate en el grupo 2');
        $this->assertCount(2, $g2['standings']);
    }
}