<?php

namespace Apps\Tournaments\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use PDO;

/**
 * Clasificación en servidor (M2, D4) — fuente de verdad única.
 *
 * Espejo EXACTO del frontend (`modules/mis-torneos/lib/stats-partidos.ts`,
 * `tablaPosiciones`): partido "jugado" = ganador O marcador cargado; stat
 * principal = primer stat type 'number' (si no, el primero); victoria 3,
 * empate 1; af/ec del marcador del stat principal; orden pts desc → af desc →
 * pj desc → nombre asc.
 *
 * Cache en memoria de 30 s por torneo; invalidate() al escribir partidos.
 */
class StandingsService
{
    private const CACHE_TTL = 30;

    /** @var array<int, array{ts: int, data: array}> */
    private static array $cache = [];

    /**
     * Invalida la caché de un torneo (llamado por MatchService al crear/
     * editar/eliminar resultados).
     */
    public static function invalidate(int $tournamentId): void
    {
        unset(self::$cache[$tournamentId]);
    }

    /**
     * Tabla de posiciones global (+ por grupo si el formato es groups y el
     * draw los define).
     */
    public function compute(int $tournamentId): array
    {
        $cached = self::$cache[$tournamentId] ?? null;
        if ($cached !== null && (time() - $cached['ts']) < self::CACHE_TTL) {
            return $cached['data'];
        }

        $pdo = DatabaseManager::getConnection();

        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tournament) {
            throw new \RuntimeException('Torneo no encontrado', 404);
        }

        $principal = $this->principalStat($this->stats($pdo, $tournamentId));
        $participants = $this->participants($pdo, $tournamentId);
        $matches = $this->matchesWithScores($pdo, $tournamentId);

        $data = [
            'standings' => $this->buildRows($participants, $matches, $principal),
        ];

        if (($tournament['format'] ?? '') === 'groups') {
            $groups = $this->groups($pdo, $tournamentId);
            if ($groups !== []) {
                $data['groups'] = $this->buildGroups($groups, $participants, $matches, $principal);
            }
        }

        self::$cache[$tournamentId] = ['ts' => time(), 'data' => $data];
        return $data;
    }

    /** @return array<int, array{id: int, label: string, type: string}> */
    private function stats(PDO $pdo, int $tournamentId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM tournament_stats WHERE tournament_id = ? ORDER BY id ASC');
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Stat principal (espejo de statPrincipal): primer type 'number', si no el
     * primero; null cuando no hay stats.
     */
    private function principalStat(array $stats): ?array
    {
        foreach ($stats as $stat) {
            if (($stat['type'] ?? '') === 'number') {
                return $stat;
            }
        }
        return $stats[0] ?? null;
    }

    /** @return array<int, array{id: int, name: string, seed: int}> */
    private function participants(PDO $pdo, int $tournamentId): array
    {
        $stmt = $pdo->prepare(
            'SELECT tp.id, tp.seed, COALESCE(t.name, p.name) AS name
             FROM tournament_participants tp
             LEFT JOIN teams t ON t.id = tp.team_id
             LEFT JOIN players p ON p.id = tp.player_id
             WHERE tp.tournament_id = ?
             ORDER BY tp.seed ASC, tp.id ASC'
        );
        $stmt->execute([$tournamentId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'seed' => (int) $row['seed'],
            ];
        }
        return $result;
    }

    /**
     * Partidos del torneo con sus marcadores por stat.
     * Cada match: ['a', 'b', 'winner', 'scores' => [statId => ['a'=>x,'b'=>y]]].
     */
    private function matchesWithScores(PDO $pdo, int $tournamentId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, participant_a_id, participant_b_id, winner_participant_id
             FROM matches WHERE tournament_id = ?'
        );
        $stmt->execute([$tournamentId]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($matches as $m) {
            $result[(int) $m['id']] = [
                'a' => $m['participant_a_id'] !== null ? (int) $m['participant_a_id'] : null,
                'b' => $m['participant_b_id'] !== null ? (int) $m['participant_b_id'] : null,
                'winner' => $m['winner_participant_id'] !== null ? (int) $m['winner_participant_id'] : null,
                'scores' => [],
            ];
        }

        if ($matches !== []) {
            $ids = array_column($matches, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT match_id, stat_id, score_a, score_b FROM match_scores WHERE match_id IN ({$in})"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $matchId = (int) $row['match_id'];
                $result[$matchId]['scores'][(int) $row['stat_id']] = [
                    'a' => (float) $row['score_a'],
                    'b' => (float) $row['score_b'],
                ];
            }
        }

        return array_values($result);
    }

    /**
     * Construcción de filas (espejo de tablaPosiciones) y orden:
     * pts desc → af desc → pj desc → nombre asc.
     */
    private function buildRows(array $participants, array $matches, ?array $principal): array
    {
        $rows = [];
        foreach ($participants as $id => $p) {
            $rows[$id] = [
                'participant_id' => $id,
                'name' => $p['name'],
                'seed' => $p['seed'],
                'pj' => 0,
                'g' => 0,
                'e' => 0,
                'p' => 0,
                'af' => 0.0,
                'ec' => 0.0,
                'pts' => 0,
            ];
        }

        $principalId = $principal['id'] ?? null;

        foreach ($matches as $m) {
            // partidoJugado: ganador O marcador cargado (cualquier stat).
            if ($m['winner'] === null && $m['scores'] === []) {
                continue;
            }

            $sc = $principalId !== null && isset($m['scores'][$principalId])
                ? $m['scores'][$principalId]
                : ['a' => 0.0, 'b' => 0.0];
            $a = (float) $sc['a'];
            $b = (float) $sc['b'];

            $filaA = $m['a'] !== null ? ($rows[$m['a']] ?? null) : null;
            $filaB = $m['b'] !== null ? ($rows[$m['b']] ?? null) : null;

            if ($filaA !== null) {
                $filaA['pj'] += 1;
                $filaA['af'] += $a;
                $filaA['ec'] += $b;
                if ($a > $b) {
                    $filaA['g'] += 1;
                    $filaA['pts'] += 3;
                } elseif ($a === $b) {
                    $filaA['e'] += 1;
                    $filaA['pts'] += 1;
                } else {
                    $filaA['p'] += 1;
                }
                $rows[$m['a']] = $filaA;
            }

            if ($filaB !== null) {
                $filaB['pj'] += 1;
                $filaB['af'] += $b;
                $filaB['ec'] += $a;
                if ($b > $a) {
                    $filaB['g'] += 1;
                    $filaB['pts'] += 3;
                } elseif ($b === $a) {
                    $filaB['e'] += 1;
                    $filaB['pts'] += 1;
                } else {
                    $filaB['p'] += 1;
                }
                $rows[$m['b']] = $filaB;
            }
        }

        usort($rows, static function (array $x, array $y): int {
            return $y['pts'] <=> $x['pts']
                ?: $y['af'] <=> $x['af']
                ?: $y['pj'] <=> $x['pj']
                ?: strcmp($x['name'], $y['name']);
        });

        return array_values($rows);
    }

    /**
     * Grupos del draw vigente (versión máxima) con sus participantes.
     *
     * @return array<int, array{id: int, name: string, position: int, participant_ids: int[]}>
     */
    private function groups(PDO $pdo, int $tournamentId): array
    {
        $stmt = $pdo->prepare(
            'SELECT dg.id, dg.name, dg.position
             FROM draw_groups dg
             JOIN draws d ON d.id = dg.draw_id
             WHERE d.tournament_id = ? AND d.version = (
                SELECT MAX(version) FROM draws WHERE tournament_id = ?
             )
             ORDER BY dg.position ASC'
        );
        $stmt->execute([$tournamentId, $tournamentId]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($groups === []) {
            return [];
        }

        $result = [];
        foreach ($groups as $g) {
            $result[(int) $g['id']] = [
                'id' => (int) $g['id'],
                'name' => (string) $g['name'],
                'position' => (int) $g['position'],
                'participant_ids' => [],
            ];
        }

        $ids = array_column($groups, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT group_id, tournament_participant_id FROM draw_group_participants WHERE group_id IN ({$in})"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['group_id']]['participant_ids'][] = (int) $row['tournament_participant_id'];
        }

        return array_values($result);
    }

    private function buildGroups(array $groups, array $participants, array $matches, ?array $principal): array
    {
        $out = [];
        foreach ($groups as $group) {
            $members = [];
            foreach ($group['participant_ids'] as $id) {
                if (isset($participants[$id])) {
                    $members[$id] = $participants[$id];
                }
            }
            // Partidos con ambos participantes dentro del grupo.
            $groupMatches = array_values(array_filter(
                $matches,
                static fn(array $m): bool => isset($members[$m['a'] ?? -1]) && isset($members[$m['b'] ?? -2])
            ));

            $out[] = [
                'group_id' => $group['id'],
                'name' => $group['name'],
                'standings' => $this->buildRows($members, $groupMatches, $principal),
            ];
        }
        return $out;
    }
}