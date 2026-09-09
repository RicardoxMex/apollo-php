<?php

namespace Apps\Tournaments\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use PDO;

class DrawService
{
    public function __construct(private AuditLogService $audit)
    {
    }

    private function pdo(): PDO
    {
        return DatabaseManager::getConnection();
    }

    /**
     * Generates (or re-generates) the tournament draw. Each generation creates a new version.
     * - type=bracket: rounds + fixtures + official matches (1:1), with byes and
     *   automatic advancement on completion.
     * - type=groups: distribution in groups + round-robin fixtures (jornadas)
     *   as official matches.
     * - type=manual: explicit group assignment ({groups:[{name?, participant_ids}]})
     *   validated (every participant exactly once) + same fixtures.
     */
    public function generate(int $actorId, int $tournamentId, array $data, ?Request $request = null): array
    {
        $pdo = $this->pdo();

        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tournament) {
            throw new \RuntimeException('Torneo no encontrado', 404);
        }
        if ((int) $tournament['organizer_id'] !== $actorId) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }
        if (!in_array($tournament['status'], ['draft', 'open'], true)) {
            throw new \RuntimeException('El sorteo solo se genera en borrador o abierto a inscripciones', 409);
        }

        $type = $data['type'] ?? 'bracket';
        if (!in_array($type, ['groups', 'bracket', 'manual'], true)) {
            throw new \InvalidArgumentException('Tipo de sorteo inválido: groups, bracket o manual');
        }

        // Accepted participants (the seed order defines the bracket/groups)
        $stmt = $pdo->prepare('SELECT id FROM tournament_participants WHERE tournament_id = ? ORDER BY seed ASC, id ASC');
        $stmt->execute([$tournamentId]);
        $participantIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        if ($type === 'bracket' && count($participantIds) < 2) {
            throw new \RuntimeException('Se necesitan al menos 2 participantes para el bracket', 409);
        }

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) AS v FROM draws WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        $version = ((int) $stmt->fetchColumn()) + 1;

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO draws (tournament_id, type, version, generated_at, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$tournamentId, $type, $version, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $drawId = (int) $pdo->lastInsertId();

            if ($type === 'bracket') {
                $this->createBracket($pdo, $drawId, $participantIds);
            } else {
                $groups = $type === 'manual'
                    ? $this->validateManualGroups($pdo, $tournamentId, $participantIds, $data['groups'] ?? [])
                    : BracketGenerator::assignGroups($participantIds, max(2, (int) ($data['num_groups'] ?? 2)));
                $this->createGroups($pdo, $drawId, $groups);
                $this->createGroupFixtures($pdo, $tournamentId, $drawId);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->record($actorId, 'draw', $drawId, 'draw:generar', null, ['type' => $type, 'version' => $version, 'participants' => count($participantIds)], $request);
        return $this->show($tournamentId);
    }

    /**
     * Removes the active draw completely (fixtures, groups, matches and scores).
     * Only in draft/paused/open; live/finished are blocked (matches may have results).
     */
    public function delete(int $actorId, int $tournamentId, ?Request $request = null): array
    {
        $pdo = $this->pdo();

        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tournament) {
            throw new \RuntimeException('Torneo no encontrado', 404);
        }
        if ((int) $tournament['organizer_id'] !== $actorId) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }
        if (in_array($tournament['status'], ['live', 'finished'], true)) {
            throw new \RuntimeException('No se puede limpiar el sorteo con el torneo en vivo o finalizado', 409);
        }

        $stmt = $pdo->prepare('SELECT id FROM draws WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        $drawIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        if ($drawIds) {
            $in = implode(',', array_fill(0, count($drawIds), '?'));
            $pdo->beginTransaction();
            try {
                // Fixtures of the draw (official matches) + their scores/stats.
                // Los fixtures de grupos no tienen draw_match_id (NULL): se
                // borran todos los partidos del torneo (todos provienen del draw).
                $stmt = $pdo->prepare('SELECT id FROM matches WHERE tournament_id = ?');
                $stmt->execute([$tournamentId]);
                $matchIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
                if ($matchIds) {
                    $inM = implode(',', array_fill(0, count($matchIds), '?'));
                    $pdo->prepare("DELETE FROM match_player_stats WHERE match_id IN ({$inM})")->execute($matchIds);
                    $pdo->prepare("DELETE FROM match_scores WHERE match_id IN ({$inM})")->execute($matchIds);
                    $pdo->prepare("DELETE FROM matches WHERE id IN ({$inM})")->execute($matchIds);
                }

                // Bracket structures
                $stmt = $pdo->prepare("SELECT id FROM draw_rounds WHERE draw_id IN ({$in})");
                $stmt->execute($drawIds);
                $roundIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
                if ($roundIds) {
                    $inR = implode(',', array_fill(0, count($roundIds), '?'));
                    $pdo->prepare("DELETE FROM draw_matches WHERE round_id IN ({$inR})")->execute($roundIds);
                    $pdo->prepare("DELETE FROM draw_rounds WHERE id IN ({$inR})")->execute($roundIds);
                }

                // Groups
                $stmt = $pdo->prepare("SELECT id FROM draw_groups WHERE draw_id IN ({$in})");
                $stmt->execute($drawIds);
                $groupIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
                if ($groupIds) {
                    $inG = implode(',', array_fill(0, count($groupIds), '?'));
                    $pdo->prepare("DELETE FROM draw_group_participants WHERE group_id IN ({$inG})")->execute($groupIds);
                    $pdo->prepare("DELETE FROM draw_groups WHERE id IN ({$inG})")->execute($groupIds);
                }

                $pdo->prepare("DELETE FROM draws WHERE id IN ({$in})")->execute($drawIds);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $this->audit->record($actorId, 'draw', $tournamentId, 'draw:limpiar', null, ['draws' => $drawIds], $request);
        }

        return $this->show($tournamentId);
    }

    /**
     * Active draw (latest version) ready for the frontend: rounds with fixtures
     * (a/b, status, scores, winner) and advances.
     */
    public function show(int $tournamentId): array
    {
        $pdo = $this->pdo();

        $stmt = $pdo->prepare('SELECT * FROM draws WHERE tournament_id = ? ORDER BY version DESC LIMIT 1');
        $stmt->execute([$tournamentId]);
        $draw = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draw) {
            return ['draw' => null, 'groups' => [], 'rounds' => []];
        }

        $result = [
            'draw' => [
                'id' => (int) $draw['id'],
                'type' => $draw['type'],
                'version' => (int) $draw['version'],
                'generated_at' => $draw['generated_at'],
            ],
            'groups' => [],
            'rounds' => [],
        ];

        if (in_array($draw['type'], ['groups', 'manual'], true)) {
            $stmt = $pdo->prepare('SELECT g.* FROM draw_groups g WHERE g.draw_id = ? ORDER BY g.position ASC');
            $stmt->execute([$draw['id']]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($groups as &$g) {
                $stmt = $pdo->prepare(
                    'SELECT dgp.tournament_participant_id, dgp.position, COALESCE(t.name, p.name) AS display_name
                     FROM draw_group_participants dgp
                     LEFT JOIN tournament_participants tp ON tp.id = dgp.tournament_participant_id
                     LEFT JOIN teams t ON t.id = tp.team_id
                     LEFT JOIN players p ON p.id = tp.player_id
                     WHERE dgp.group_id = ?
                     ORDER BY dgp.position ASC'
                );
                $stmt->execute([$g['id']]);
                $g['participants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $result['groups'] = $groups;
        }

        if ($draw['type'] === 'bracket') {
            $stmt = $pdo->prepare('SELECT * FROM draw_rounds WHERE draw_id = ? ORDER BY round_number ASC');
            $stmt->execute([$draw['id']]);
            $rounds = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rounds as $round) {
                $stmt = $pdo->prepare(
                    'SELECT dm.id, dm.match_number, dm.participant_a_id, dm.participant_b_id,
                            dm.winner_participant_id, dm.status, dm.scheduled_at,
                            dm.next_match_id, dm.next_slot,
                            m.id AS match_id, m.status AS official_status
                     FROM draw_matches dm
                     LEFT JOIN matches m ON m.draw_match_id = dm.id
                     WHERE dm.round_id = ?
                     ORDER BY dm.match_number ASC'
                );
                $stmt->execute([$round['id']]);
                $fixtures = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Official scores per fixture
                $matchIds = array_values(array_filter(array_column($fixtures, 'match_id')));
                $scoresByMatch = [];
                if ($matchIds) {
                    $in = implode(',', array_fill(0, count($matchIds), '?'));
                    $stmt = $pdo->prepare("SELECT * FROM match_scores WHERE match_id IN ({$in})");
                    $stmt->execute($matchIds);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                        $scoresByMatch[$s['match_id']][] = $s;
                    }
                }

                foreach ($fixtures as &$e) {
                    $e['id'] = (int) $e['id'];
                    $e['participant_a_id'] = $e['participant_a_id'] !== null ? (int) $e['participant_a_id'] : null;
                    $e['participant_b_id'] = $e['participant_b_id'] !== null ? (int) $e['participant_b_id'] : null;
                    $e['winner_participant_id'] = $e['winner_participant_id'] !== null ? (int) $e['winner_participant_id'] : null;
                    $e['next_match_id'] = $e['next_match_id'] !== null ? (int) $e['next_match_id'] : null;
                    $e['scores'] = $e['match_id'] !== null ? ($scoresByMatch[$e['match_id']] ?? []) : [];
                    $e['match_id'] = $e['match_id'] !== null ? (int) $e['match_id'] : null;
                }

                $result['rounds'][] = [
                    'round_number' => (int) $round['round_number'],
                    'name' => $round['name'],
                    'matches' => $fixtures,
                ];
            }
        }

        return $result;
    }

    private function createBracket(PDO $pdo, int $drawId, array $participantIds): void
    {
        $structure = BracketGenerator::generateBracket($participantIds);

        // Rounds
        $roundIds = [];
        $maxRound = max(array_column($structure, 'round_number'));
        $roundStmt = $pdo->prepare('INSERT INTO draw_rounds (draw_id, round_number, name) VALUES (?, ?, ?)');
        for ($r = 1; $r <= $maxRound; $r++) {
            $roundStmt->execute([$drawId, $r, "Ronda {$r}"]);
            $roundIds[$r] = (int) $pdo->lastInsertId();
        }

        // Fixtures (2 passes: insert all and then chain next_match)
        $matchStmt = $pdo->prepare(
            "INSERT INTO draw_matches (round_id, match_number, participant_a_id, participant_b_id, status, scheduled_at, next_match_id, next_slot)
             VALUES (?, ?, ?, ?, 'pending', ?, NULL, NULL)"
        );
        $officialStmt = $pdo->prepare(
            "INSERT INTO matches (tournament_id, draw_match_id, round_number, match_number, participant_a_id, participant_b_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
        );

        $byId = [];
        foreach ($structure as $i => $m) {
            $matchStmt->execute([$roundIds[$m['round_number']], $m['match_number'], $m['participant_a_id'], $m['participant_b_id'], null]);
            $drawMatchId = (int) $pdo->lastInsertId();
            $byId[$i] = $drawMatchId;

            $officialStmt->execute([$this->tournamentOfDraw($pdo, $drawId), $drawMatchId, $m['round_number'], $m['match_number'], $m['participant_a_id'], $m['participant_b_id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        }

        foreach ($structure as $i => $m) {
            if ($m['next_match_index'] !== null && isset($byId[$m['next_match_index']])) {
                $pdo->prepare('UPDATE draw_matches SET next_match_id = ?, next_slot = ? WHERE id = ?')
                    ->execute([$byId[$m['next_match_index']], $m['next_slot'], $byId[$i]]);
            }
        }
    }

    private function tournamentOfDraw(PDO $pdo, int $drawId): int
    {
        $stmt = $pdo->prepare('SELECT tournament_id FROM draws WHERE id = ?');
        $stmt->execute([$drawId]);
        return (int) $stmt->fetchColumn();
    }

    private function createGroups(PDO $pdo, int $drawId, array $groups): void
    {
        $groupStmt = $pdo->prepare('INSERT INTO draw_groups (draw_id, name, position) VALUES (?, ?, ?)');
        $pivotStmt = $pdo->prepare('INSERT INTO draw_group_participants (group_id, tournament_participant_id, position) VALUES (?, ?, ?)');

        foreach ($groups as $i => $g) {
            $groupStmt->execute([$drawId, $g['name'], $g['position']]);
            $groupId = (int) $pdo->lastInsertId();
            foreach (array_values($g['participant_ids']) as $pos => $pid) {
                $pivotStmt->execute([$groupId, $pid, $pos + 1]);
            }
        }
    }

    /**
     * Validates the explicit manual assignment: every accepted participant of the
     * tournament appears exactly once across the provided groups.
     *
     * @return array{name:string, position:int, participant_ids:int[]}[]
     */
    private function validateManualGroups(PDO $pdo, int $tournamentId, array $participantIds, array $groups): array
    {
        if (empty($groups) || !is_array($groups)) {
            throw new \InvalidArgumentException('El sorteo manual requiere la asignación de grupos');
        }

        $seen = [];
        $normalized = [];
        foreach (array_values($groups) as $i => $g) {
            $ids = array_map('intval', $g['participant_ids'] ?? []);
            $ids = array_values(array_filter($ids, fn($id) => $id > 0));
            foreach ($ids as $id) {
                if (isset($seen[$id])) {
                    throw new \RuntimeException("El participante {$id} aparece en más de un grupo", 409);
                }
                $seen[$id] = true;
            }
            $normalized[] = [
                'name' => trim($g['name'] ?? '') !== '' ? trim($g['name']) : 'Grupo ' . chr(65 + $i),
                'position' => $i + 1,
                'participant_ids' => $ids,
            ];
        }

        foreach ($participantIds as $id) {
            if (!isset($seen[$id])) {
                throw new \RuntimeException("El participante {$id} no fue asignado a ningún grupo", 409);
            }
        }

        return array_filter($normalized, fn($g) => count($g['participant_ids']) > 0);
    }

    /**
     * Round-robin fixtures per group as official matches (jornadas).
     * `round_number` is the jornada (per group, from 1); `match_number` is global.
     */
    private function createGroupFixtures(PDO $pdo, int $tournamentId, int $drawId): void
    {
        $stmt = $pdo->prepare('SELECT g.id FROM draw_groups g WHERE g.draw_id = ? ORDER BY g.position ASC');
        $stmt->execute([$drawId]);
        $groupIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        $insert = $pdo->prepare(
            "INSERT INTO matches (tournament_id, draw_match_id, round_number, match_number, participant_a_id, participant_b_id, status, created_at, updated_at)
             VALUES (?, NULL, ?, ?, ?, ?, 'pending', ?, ?)"
        );

        foreach ($groupIds as $groupId) {
            $stmt = $pdo->prepare('SELECT tournament_participant_id FROM draw_group_participants WHERE group_id = ? ORDER BY position ASC');
            $stmt->execute([$groupId]);
            $participantIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'tournament_participant_id'));

            foreach (BracketGenerator::generateRoundRobin($participantIds) as $m) {
                $insert->execute([
                    $tournamentId,
                    $m['round_number'],
                    $m['match_number'],
                    $m['participant_a_id'],
                    $m['participant_b_id'],
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}