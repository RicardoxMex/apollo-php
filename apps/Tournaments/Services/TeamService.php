<?php

namespace Apps\Tournaments\Services;

use Apps\Tournaments\Repositories\TeamRepository;
use Apollo\Core\Database\Connection\DatabaseManager;
use PDO;

class TeamService
{
    public function __construct(
        private TeamRepository $teams,
        private AuditLogService $audit,
    ) {
    }

    /**
     * Active teams (no soft-delete), with optional search.
     */
    public function list(?string $q = null): array
    {
        $sql = 'SELECT t.*, COUNT(tp.player_id) AS players_count
                FROM teams t
                LEFT JOIN team_players tp ON tp.team_id = t.id
                WHERE t.deleted_at IS NULL';
        $params = [];
        if (!empty($q)) {
            $sql .= ' AND (t.name LIKE ? OR t.contact LIKE ?)';
            $params = ["%{$q}%", "%{$q}%"];
        }
        $sql .= ' GROUP BY t.id ORDER BY t.name ASC';

        $stmt = DatabaseManager::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function show(int $id): ?array
    {
        $team = $this->teams->find($id);
        if (!$team || $team['deleted_at'] !== null) {
            return null;
        }

        $pdo = DatabaseManager::getConnection();

        $stmt = $pdo->prepare('SELECT u.id, u.email, u.first_name, u.last_name, u.username FROM team_captains tc JOIN users u ON u.id = tc.user_id WHERE tc.team_id = ?');
        $stmt->execute([$id]);
        $captains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($captains as &$c) {
            $c['name'] = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: ($c['username'] ?? '');
            unset($c['first_name'], $c['last_name'], $c['username']);
        }
        $team['captains'] = $captains;

        $stmt = $pdo->prepare('SELECT p.id, p.name, p.jersey_number AS player_jersey_number, tp.jersey_number AS team_jersey_number, tp.joined_at, tp.left_at
                               FROM team_players tp JOIN players p ON p.id = tp.player_id WHERE tp.team_id = ?');
        $stmt->execute([$id]);
        $team['players'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $team;
    }

    /**
     * Creates a team. The actor becomes captain by default; it accepts players[]
     * with {name?, player_id?, jersey_number} (creates players if they do not exist).
     */
    public function create(int $actorId, array $data): ?array
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del equipo es obligatorio');
        }

        $pdo = DatabaseManager::getConnection();

        $pdo->beginTransaction();
        try {
            $id = $this->teams->create([
                'name' => $name,
                'contact' => $data['contact'] ?? null,
                'image' => $data['image'] ?? null,
            ]);

            // Captains: if none specified, the creator captains the team
            $captains = !empty($data['captains']) && is_array($data['captains'])
                ? array_map('intval', $data['captains'])
                : [$actorId];
            foreach (array_unique($captains) as $userId) {
                if ($userId > 0) {
                    // In MySQL: INSERT IGNORE; here try/catch for portability (composite PK protects)
                    try {
                        $pdo->prepare('INSERT INTO team_captains (team_id, user_id) VALUES (?, ?)')->execute([$id, $userId]);
                    } catch (\Throwable $ignored) {
                        // Captain already registered: no effect
                    }
                }
            }

            if (!empty($data['players']) && is_array($data['players'])) {
                $this->syncPlayers($pdo, (int) $id, $data['players']);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->record($actorId, 'team', (int) $id, 'equipo:crear', null, ['name' => $name]);
        return $this->show((int) $id);
    }

    public function update(int $actorId, int $id, array $data): ?array
    {
        $team = $this->teams->find($id);
        if (!$team || $team['deleted_at'] !== null) {
            return null;
        }

        $pdo = DatabaseManager::getConnection();
        $pdo->beginTransaction();
        try {
            $this->teams->update($id, array_filter([
                'name' => $data['name'] ?? null,
                'contact' => $data['contact'] ?? null,
                'image' => $data['image'] ?? null,
            ], fn($v) => $v !== null));

            if (isset($data['players']) && is_array($data['players'])) {
                $this->syncPlayers($pdo, $id, $data['players']);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->record($actorId, 'team', $id, 'equipo:actualizar', $team);
        return $this->show($id);
    }

    public function delete(int $actorId, int $id): bool
    {
        $team = $this->teams->find($id);
        if (!$team || $team['deleted_at'] !== null) {
            return false;
        }

        $this->teams->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->audit->record($actorId, 'team', $id, 'equipo:eliminar', $team);
        return true;
    }

    /**
     * Inserts players (creating them if needed) and syncs team_players.
     * Existing players in the team that are not in the list are retired (left_at).
     */
    private function syncPlayers(PDO $pdo, int $teamId, array $players): void
    {
        $inscribedIds = [];
        foreach ($players as $p) {
            $playerId = (int) ($p['player_id'] ?? 0);
            $name = trim($p['name'] ?? '');
            $jerseyNumber = $p['jersey_number'] ?? null;

            if ($playerId === 0 && $name !== '') {
                $pdo->prepare('INSERT INTO players (name, jersey_number, created_at, updated_at) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $jerseyNumber, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                $playerId = (int) $pdo->lastInsertId();
            }
            if ($playerId === 0) {
                continue;
            }

            $pdo->prepare('INSERT INTO team_players (team_id, player_id, jersey_number, joined_at)
                           VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE jersey_number = VALUES(jersey_number), left_at = NULL')
                ->execute([$teamId, $playerId, $jerseyNumber, date('Y-m-d H:i:s')]);
            $inscribedIds[] = $playerId;
        }

        // Retire (left_at) those no longer in the list
        if (!empty($inscribedIds)) {
            $in = implode(',', array_fill(0, count($inscribedIds), '?'));
            $pdo->prepare("UPDATE team_players SET left_at = ? WHERE team_id = ? AND player_id NOT IN ({$in}) AND left_at IS NULL")
                ->execute(array_merge([date('Y-m-d H:i:s'), $teamId], $inscribedIds));
        } else {
            $pdo->prepare('UPDATE team_players SET left_at = ? WHERE team_id = ? AND left_at IS NULL')
                ->execute([date('Y-m-d H:i:s'), $teamId]);
        }
    }
}