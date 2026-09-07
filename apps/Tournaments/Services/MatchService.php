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

    public function listar(int $torneoId): array
    {
        $partidos = $this->matches->listWithDetails($torneoId);

        $pdo = $this->pdo();
        $ids = array_column($partidos, 'id');
        $nombres = [];
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
                $nombres[(int) $r['participant_id']] = $r['display_name'];
            }
        }

        foreach ($partidos as &$m) {
            $m['display_a'] = $m['participant_a_id'] !== null ? ($nombres[(int) $m['participant_a_id']] ?? null) : null;
            $m['display_b'] = $m['participant_b_id'] !== null ? ($nombres[(int) $m['participant_b_id']] ?? null) : null;
        }

        return $partidos;
    }

    /**
     * Actualiza un partido oficial (solo organizador, torneo en live):
     * estado, fecha, marcadores por stat, stats por jugador y ganador.
     * Al completar propaga status/ganador al bracket (draw_match) y avanza al
     * siguiente enfrentamiento (round-trip BRK-03/MVP-B del frontend).
     */
    public function actualizar(int $actorId, int $torneoId, int $matchId, array $data, ?Request $request = null): array
    {
        $pdo = $this->pdo();

        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$torneoId]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$torneo) {
            throw new \RuntimeException('Torneo no encontrado', 404);
        }
        if ((int) $torneo['organizer_id'] !== $actorId) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }
        if ($torneo['status'] !== 'live') {
            throw new \RuntimeException('Los partidos solo se actualizan con el torneo en vivo', 409);
        }

        $stmt = $pdo->prepare('SELECT * FROM matches WHERE id = ? AND tournament_id = ?');
        $stmt->execute([$matchId, $torneoId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            throw new \RuntimeException('Partido no encontrado', 404);
        }

        $nuevoEstado = $data['status'] ?? $match['status'];
        if (!in_array($nuevoEstado, ['pending', 'scheduled', 'live', 'completed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Estado de partido inválido');
        }

        $campos = ['status' => $nuevoEstado];
        if (isset($data['scheduled_at'])) {
            $campos['scheduled_at'] = $data['scheduled_at'];
        }
        if ($nuevoEstado === 'live') {
            $campos['started_at'] = $campos['started_at'] ?? $match['started_at'] ?? date('Y-m-d H:i:s');
        }
        if (in_array($nuevoEstado, ['completed', 'cancelled'], true)) {
            $campos['finished_at'] = date('Y-m-d H:i:s');
        }

        $pdo->beginTransaction();
        try {
            // Marcadores por stat (reescritura idempotente dentro de la transacción)
            if (!empty($data['scores']) && is_array($data['scores'])) {
                $pdo->prepare('DELETE FROM match_scores WHERE match_id = ?')->execute([$matchId]);
                $stmt = $pdo->prepare('INSERT INTO match_scores (match_id, stat_id, score_a, score_b) VALUES (?, ?, ?, ?)');
                foreach ($data['scores'] as $s) {
                    $stmt->execute([$matchId, (int) $s['stat_id'], $s['a'] ?? 0, $s['b'] ?? 0]);
                }
            }

            // Stats por jugador/participante (idem)
            if (!empty($data['player_stats']) && is_array($data['player_stats'])) {
                $pdo->prepare('DELETE FROM match_player_stats WHERE match_id = ?')->execute([$matchId]);
                $stmt = $pdo->prepare('INSERT INTO match_player_stats (match_id, participant_id, player_id, stat_id, value) VALUES (?, ?, ?, ?, ?)');
                foreach ($data['player_stats'] as $ps) {
                    $stmt->execute([$matchId, (int) $ps['participant_id'], $ps['player_id'] ?? null, (int) $ps['stat_id'], $ps['value'] ?? 0]);
                }
            }

            // Ganador: explícito, inferido del primer marcador desigual, o unico participante (bye)
            $ganador = $data['winner_participant_id'] ?? null;
            if ($nuevoEstado === 'completed') {
                $ganador = $this->resolverGanador($pdo, $match, $ganador);
                if ($ganador === null) {
                    throw new \RuntimeException('Registra el ganador o un marcador que lo determine', 409);
                }
                $campos['winner_participant_id'] = $ganador;
            }

            $set = [];
            $params = [];
            foreach ($campos as $k => $v) {
                $set[] = "`{$k}` = ?";
                $params[] = $v;
            }
            $set[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
            $params[] = $matchId;
            $pdo->prepare('UPDATE matches SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

            // Propagación al bracket (draw_match) + avance del ganador
            if (!empty($match['draw_match_id']) && in_array($nuevoEstado, ['completed', 'cancelled'], true)) {
                $this->propagarAlBracket($pdo, (int) $match['draw_match_id'], $nuevoEstado, $ganador);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->registrar($actorId, 'match', $matchId, "partido:$nuevoEstado", $match, $campos, $request);

        $stmt = $pdo->prepare('SELECT * FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function resolverGanador(PDO $pdo, array $match, $ganadorExplicito): ?int
    {
        if ($ganadorExplicito !== null && $ganadorExplicito !== '' && $ganadorExplicito !== 0) {
            return (int) $ganadorExplicito;
        }

        // Bye: un solo participante avanza directo
        if ($match['participant_a_id'] !== null && $match['participant_b_id'] === null) {
            return (int) $match['participant_a_id'];
        }
        if ($match['participant_a_id'] === null && $match['participant_b_id'] !== null) {
            return (int) $match['participant_b_id'];
        }

        // Inferir del primer score con diferencia
        $stmt = $pdo->prepare('SELECT score_a, score_b FROM match_scores WHERE match_id = ?'); // TODO: elegir stat principal (id menor)
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
     * Actualiza el enfrentamiento del bracket y avanza el ganador al siguiente partido.
     */
    private function propagarAlBracket(PDO $pdo, int $drawMatchId, string $estado, ?int $ganador): void
    {
        $pdo->prepare('UPDATE draw_matches SET status = ?, winner_participant_id = ? WHERE id = ?')
            ->execute([$estado, $ganador, $drawMatchId]);

        $stmt = $pdo->prepare('SELECT next_match_id, next_slot FROM draw_matches WHERE id = ?');
        $stmt->execute([$drawMatchId]);
        $siguiente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$siguiente || $siguiente['next_match_id'] === null || $ganador === null) {
            return;
        }

        $columna = $siguiente['next_slot'] === 'b' ? 'participant_b_id' : 'participant_a_id';
        $pdo->prepare("UPDATE draw_matches SET {$columna} = ? WHERE id = ?")
            ->execute([$ganador, $siguiente['next_match_id']]);

        // El partido oficial siguiente también refleja el avance
        $stmt = $pdo->prepare('SELECT id FROM matches WHERE draw_match_id = ?');
        $stmt->execute([$siguiente['next_match_id']]);
        $nextOfficialId = $stmt->fetchColumn();
        if ($nextOfficialId) {
            $pdo->prepare("UPDATE matches SET {$columna} = ? WHERE id = ?")
                ->execute([$ganador, $nextOfficialId]);
        }
    }
}