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
     * - type=groups: distribution in groups (no fixtures yet).
     * - type=manual: only records the draw.
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

        // Accepted participants (the seed order defines the bracket)
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
            } elseif ($type === 'groups') {
                $numGroups = max(2, (int) ($data['num_groups'] ?? 2));
                $this->createGroups($pdo, $drawId, BracketGenerator::assignGroups($participantIds, $numGroups));
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

        if ($draw['type'] === 'groups') {
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
}