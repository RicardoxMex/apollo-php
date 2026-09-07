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
     * Equipos activos (sin soft-delete), con búsqueda opcional.
     */
    public function listar(?string $q = null): array
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

    public function mostrar(int $id): ?array
    {
        $equipo = $this->teams->find($id);
        if (!$equipo || $equipo['deleted_at'] !== null) {
            return null;
        }

        $pdo = DatabaseManager::getConnection();

        $stmt = $pdo->prepare('SELECT u.id, u.email, u.first_name, u.last_name, u.username FROM team_captains tc JOIN users u ON u.id = tc.user_id WHERE tc.team_id = ?');
        $stmt->execute([$id]);
        $capitanes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($capitanes as &$c) {
            $c['name'] = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: ($c['username'] ?? '');
            unset($c['first_name'], $c['last_name'], $c['username']);
        }
        $equipo['captains'] = $capitanes;

        $stmt = $pdo->prepare('SELECT p.id, p.name, p.jersey_number AS player_jersey_number, tp.jersey_number AS team_jersey_number, tp.joined_at, tp.left_at
                               FROM team_players tp JOIN players p ON p.id = tp.player_id WHERE tp.team_id = ?');
        $stmt->execute([$id]);
        $equipo['players'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $equipo;
    }

    /**
     * Crea un equipo. El actor queda como capitán por defecto; admite players[]
     * con {name?, player_id?, jersey_number} (crea jugadores si no existen).
     */
    public function crear(int $actorId, array $data): ?array
    {
        $nombre = trim($data['name'] ?? '');
        if ($nombre === '') {
            throw new \InvalidArgumentException('El nombre del equipo es obligatorio');
        }

        $pdo = DatabaseManager::getConnection();

        $pdo->beginTransaction();
        try {
            $id = $this->teams->create([
                'name' => $nombre,
                'contact' => $data['contact'] ?? null,
                'image' => $data['image'] ?? null,
            ]);

            // Capitanes: si no se especifican, el creador capitanea el equipo
            $capitanes = !empty($data['captains']) && is_array($data['captains'])
                ? array_map('intval', $data['captains'])
                : [$actorId];
            foreach (array_unique($capitanes) as $userId) {
                if ($userId > 0) {
                    // En MySQL: INSERT IGNORE; aquí try/catch para portabilidad (PK compuesta protege)
                    try {
                        $pdo->prepare('INSERT INTO team_captains (team_id, user_id) VALUES (?, ?)')->execute([$id, $userId]);
                    } catch (\Throwable $ignored) {
                        // Capitán ya registrado: sin efecto
                    }
                }
            }

            if (!empty($data['players']) && is_array($data['players'])) {
                $this->sincronizarJugadores($pdo, (int) $id, $data['players']);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->registrar($actorId, 'team', (int) $id, 'equipo:crear', null, ['name' => $nombre]);
        return $this->mostrar((int) $id);
    }

    public function actualizar(int $actorId, int $id, array $data): ?array
    {
        $equipo = $this->teams->find($id);
        if (!$equipo || $equipo['deleted_at'] !== null) {
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
                $this->sincronizarJugadores($pdo, $id, $data['players']);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->registrar($actorId, 'team', $id, 'equipo:actualizar', $equipo);
        return $this->mostrar($id);
    }

    public function eliminar(int $actorId, int $id): bool
    {
        $equipo = $this->teams->find($id);
        if (!$equipo || $equipo['deleted_at'] !== null) {
            return false;
        }

        $this->teams->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->audit->registrar($actorId, 'team', $id, 'equipo:eliminar', $equipo);
        return true;
    }

    /**
     * Inserta jugadores (creándolos si hace falta) y sincroniza team_players.
     * Los jugadores existentes en la lista que no vienen en la edición se retiran (left_at).
     */
    private function sincronizarJugadores(PDO $pdo, int $teamId, array $jugadores): void
    {
        $nuevosInscritos = [];
        foreach ($jugadores as $j) {
            $playerId = (int) ($j['player_id'] ?? 0);
            $nombre = trim($j['name'] ?? '');
            $dorsal = $j['jersey_number'] ?? null;

            if ($playerId === 0 && $nombre !== '') {
                $pdo->prepare('INSERT INTO players (name, jersey_number, created_at, updated_at) VALUES (?, ?, ?, ?)')
                    ->execute([$nombre, $dorsal, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                $playerId = (int) $pdo->lastInsertId();
            }
            if ($playerId === 0) {
                continue;
            }

            $pdo->prepare('INSERT INTO team_players (team_id, player_id, jersey_number, joined_at)
                           VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE jersey_number = VALUES(jersey_number), left_at = NULL')
                ->execute([$teamId, $playerId, $dorsal, date('Y-m-d H:i:s')]);
            $nuevosInscritos[] = $playerId;
        }

        // Retirar (left_at) los que ya no están en la lista
        if (!empty($nuevosInscritos)) {
            $in = implode(',', array_fill(0, count($nuevosInscritos), '?'));
            $pdo->prepare("UPDATE team_players SET left_at = ? WHERE team_id = ? AND player_id NOT IN ({$in}) AND left_at IS NULL")
                ->execute(array_merge([date('Y-m-d H:i:s'), $teamId], $nuevosInscritos));
        } else {
            $pdo->prepare('UPDATE team_players SET left_at = ? WHERE team_id = ? AND left_at IS NULL')
                ->execute([date('Y-m-d H:i:s'), $teamId]);
        }
    }
}