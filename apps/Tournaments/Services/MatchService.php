<?php

namespace Apps\Tournaments\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use PDO;

class MatchService
{
    public function __construct(
        private \Apps\Tournaments\Repositories\MatchRepository $matches,
        private AuditLogService $audit,
    ) {
    }

    private function pdo(): PDO
    {
        return DatabaseManager::getConnection();
    }

    public function list(int $tournamentId): array
    {
        $matches = $this->matches->listWithDetails($tournamentId);

        $pdo = $this->pdo();
        $ids = array_column($matches, 'id');
        $names = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT tp.id AS participant_id, COALESCE(t.name, p.name) AS display_name
                 FROM tournament_participants tp
                 LEFT JOIN teams t ON t.id = tp.team_id
                 LEFT JOIN players p ON p.id = tp.player_id
                 WHERE tp.id IN (
                    SELECT participant_a_id FROM matches WHERE id IN ({$in})
                    UNION SELECT participant_b_id FROM matches WHERE id IN ({$in})
                 )"
            );
            $stmt->execute(array_merge($ids, $ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $names[(int) $r['participant_id']] = $r['display_name'];
            }
        }

        foreach ($matches as &$m) {
            $m['display_a'] = $m['participant_a_id'] !== null ? ($names[(int) $m['participant_a_id']] ?? null) : null;
            $m['display_b'] = $m['participant_b_id'] !== null ? ($names[(int) $m['participant_b_id']] ?? null) : null;
        }

        return $matches;
    }

    /**
     * Creates an official match (organizer only; not allowed when finished):
     * participants, round and status. Used by the frontend when generating
     * round-robin jornadas or registering a game manually.
     */
    public function create(int $actorId, int $tournamentId, array $data): ?array
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
        if ($tournament['status'] === 'finished') {
            throw new \RuntimeException('No se pueden crear partidos en un torneo finalizado', 409);
        }

        $round = max(1, (int) ($data['round_number'] ?? 1));
        $status = $data['status'] ?? 'pending';
        if (!in_array($status, ['pending', 'scheduled', 'live'], true)) {
            throw new \InvalidArgumentException('Estado de partido inválido');
        }
        $label = isset($data['label']) && $data['label'] !== '' && $data['label'] !== null
            ? trim((string) $data['label'])
            : null;
        $participantA = isset($data['participant_a_id']) && $data['participant_a_id'] !== '' && $data['participant_a_id'] !== null
            ? (int) $data['participant_a_id']
            : null;
        $participantB = isset($data['participant_b_id']) && $data['participant_b_id'] !== '' && $data['participant_b_id'] !== null
            ? (int) $data['participant_b_id']
            : null;

        // Los participantes deben estar inscritos en el torneo.
        foreach (array_filter([$participantA, $participantB], fn ($pid) => $pid !== null) as $pid) {
            $stmt = $pdo->prepare('SELECT id FROM tournament_participants WHERE id = ? AND tournament_id = ?');
            $stmt->execute([$pid, $tournamentId]);
            if (!$stmt->fetchColumn()) {
                throw new \InvalidArgumentException('Participante no inscrito en el torneo');
            }
        }

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(match_number), 0) + 1 FROM matches WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        $matchNumber = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO matches (tournament_id, round_number, match_number, label, participant_a_id, participant_b_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tournamentId,
            $round,
            $matchNumber,
            $label,
            $participantA,
            $participantB,
            $status,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);
        $matchId = (int) $pdo->lastInsertId();

        $this->audit->record($actorId, 'match', $matchId, 'partido:crear', null, $data);

        $stmt = $pdo->prepare('SELECT * FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        $match['scores'] = [];
        $match['player_stats'] = [];
        return $match;
    }

    /**
     * Deletes an official match (organizer only, not finished). Cascades to
     * scores and per-player stats.
     */
    public function delete(int $actorId, int $tournamentId, int $matchId): bool
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
        if ($tournament['status'] === 'finished') {
            throw new \RuntimeException('No se pueden eliminar partidos en un torneo finalizado', 409);
        }

        $stmt = $pdo->prepare('SELECT * FROM matches WHERE id = ? AND tournament_id = ?');
        $stmt->execute([$matchId, $tournamentId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            throw new \RuntimeException('Partido no encontrado', 404);
        }

        $stmt = $pdo->prepare('DELETE FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);

        $this->audit->record($actorId, 'match', $matchId, 'partido:eliminar', $match);
        return true;
    }

    /**
     * Updates an official match (organizer only, tournament not finished):
     * status, date, per-stat scores, per-player stats and winner.
     * On completion it propagates status/winner to the bracket (draw_match) and
     * advances to the next fixture (round-trip BRK-03/MVP-B of the frontend).
     */
    public function update(int $actorId, int $tournamentId, int $matchId, array $data, ?Request $request = null): array
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
        if ($tournament['status'] === 'finished') {
            throw new \RuntimeException('Los partidos no se modifican con el torneo finalizado', 409);
        }

        $stmt = $pdo->prepare('SELECT * FROM matches WHERE id = ? AND tournament_id = ?');
        $stmt->execute([$matchId, $tournamentId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            throw new \RuntimeException('Partido no encontrado', 404);
        }

        $newStatus = $data['status'] ?? $match['status'];
        if (!in_array($newStatus, ['pending', 'scheduled', 'live', 'completed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Estado de partido inválido');
        }

        $fields = ['status' => $newStatus];
        if (array_key_exists('label', $data)) {
            $fields['label'] = $data['label'] !== null && $data['label'] !== '' ? trim((string) $data['label']) : null;
        }
        if (isset($data['scheduled_at'])) {
            $fields['scheduled_at'] = $data['scheduled_at'];
        }
        if ($newStatus === 'live') {
            $fields['started_at'] = $fields['started_at'] ?? $match['started_at'] ?? date('Y-m-d H:i:s');
        }
        if (in_array($newStatus, ['completed', 'cancelled'], true)) {
            $fields['finished_at'] = date('Y-m-d H:i:s');
        }

        $pdo->beginTransaction();
        try {
            // Per-stat scores (idempotent rewrite inside the transaction)
            if (!empty($data['scores']) && is_array($data['scores'])) {
                $pdo->prepare('DELETE FROM match_scores WHERE match_id = ?')->execute([$matchId]);
                $stmt = $pdo->prepare('INSERT INTO match_scores (match_id, stat_id, score_a, score_b) VALUES (?, ?, ?, ?)');
                foreach ($data['scores'] as $s) {
                    $stmt->execute([$matchId, (int) $s['stat_id'], $s['a'] ?? 0, $s['b'] ?? 0]);
                }
            }

            // Per-player/participant stats (same)
            if (!empty($data['player_stats']) && is_array($data['player_stats'])) {
                $pdo->prepare('DELETE FROM match_player_stats WHERE match_id = ?')->execute([$matchId]);
                $stmt = $pdo->prepare('INSERT INTO match_player_stats (match_id, participant_id, player_id, stat_id, value) VALUES (?, ?, ?, ?, ?)');
                foreach ($data['player_stats'] as $ps) {
                    $stmt->execute([$matchId, (int) $ps['participant_id'], $ps['player_id'] ?? null, (int) $ps['stat_id'], $ps['value'] ?? 0]);
                }
            }

            // Winner: explicit, inferred from the first unequal score, or single participant (bye)
            $winner = $data['winner_participant_id'] ?? null;
            if ($newStatus === 'completed') {
                $winner = $this->resolveWinner($pdo, $match, $winner);
                if ($winner === null) {
                    throw new \RuntimeException('Registra el ganador o un marcador que lo determine', 409);
                }
                $fields['winner_participant_id'] = $winner;
            }

            $set = [];
            $params = [];
            foreach ($fields as $k => $v) {
                $set[] = "`{$k}` = ?";
                $params[] = $v;
            }
            $set[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
            $params[] = $matchId;
            $pdo->prepare('UPDATE matches SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

            // Propagation to the bracket (draw_match) + winner advance
            if (!empty($match['draw_match_id']) && in_array($newStatus, ['completed', 'cancelled'], true)) {
                $this->propagateToBracket($pdo, (int) $match['draw_match_id'], $newStatus, $winner);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->record($actorId, 'match', $matchId, "partido:$newStatus", $match, $fields, $request);

        $stmt = $pdo->prepare('SELECT * FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function resolveWinner(PDO $pdo, array $match, $explicitWinner): ?int
    {
        if ($explicitWinner !== null && $explicitWinner !== '' && $explicitWinner !== 0) {
            return (int) $explicitWinner;
        }

        // Bye: a single participant advances directly
        if ($match['participant_a_id'] !== null && $match['participant_b_id'] === null) {
            return (int) $match['participant_a_id'];
        }
        if ($match['participant_a_id'] === null && $match['participant_b_id'] !== null) {
            return (int) $match['participant_b_id'];
        }

        // Infer from the first score with a difference
        $stmt = $pdo->prepare('SELECT score_a, score_b FROM match_scores WHERE match_id = ?'); // TODO: choose main stat (lowest id)
        $stmt->execute([$match['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if ((float) $s['score_a'] > (float) $s['score_b']) {
                return (int) $match['participant_a_id'];
            }
            if ((float) $s['score_b'] > (float) $s['score_a']) {
                return (int) $match['participant_b_id'];
            }
        }

        return null;
    }

    /**
     * Updates the bracket fixture and advances the winner to the next match.
     */
    private function propagateToBracket(PDO $pdo, int $drawMatchId, string $status, ?int $winner): void
    {
        $pdo->prepare('UPDATE draw_matches SET status = ?, winner_participant_id = ? WHERE id = ?')
            ->execute([$status, $winner, $drawMatchId]);

        $stmt = $pdo->prepare('SELECT next_match_id, next_slot FROM draw_matches WHERE id = ?');
        $stmt->execute([$drawMatchId]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$next || $next['next_match_id'] === null || $winner === null) {
            return;
        }

        $column = $next['next_slot'] === 'b' ? 'participant_b_id' : 'participant_a_id';
        $pdo->prepare("UPDATE draw_matches SET {$column} = ? WHERE id = ?")
            ->execute([$winner, $next['next_match_id']]);

        // The next official match also reflects the advance
        $stmt = $pdo->prepare('SELECT id FROM matches WHERE draw_match_id = ?');
        $stmt->execute([$next['next_match_id']]);
        $nextOfficialId = $stmt->fetchColumn();
        if ($nextOfficialId) {
            $pdo->prepare("UPDATE matches SET {$column} = ? WHERE id = ?")
                ->execute([$winner, $nextOfficialId]);
        }
    }
}