<?php

namespace Apps\Tournaments\Services;

use Apps\Tournaments\Repositories\PlayerRepository;
use Apollo\Core\Database\Connection\DatabaseManager;

class PlayerService
{
    public function __construct(
        private PlayerRepository $players,
        private AuditLogService $audit,
    ) {
    }

    public function list(?string $q = null): array
    {
        if (!empty($q)) {
            return $this->players->search($q);
        }
        return $this->players->query()->orderBy('name', 'ASC')->get();
    }

    public function show(int $id): ?array
    {
        return $this->players->find($id);
    }

    public function create(int $actorId, array $data): ?string
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del jugador es obligatorio');
        }

        $id = $this->players->create([
            'user_id' => !empty($data['user_id']) ? (int) $data['user_id'] : null,
            'name' => $name,
            'jersey_number' => $data['jersey_number'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
        ]);

        $this->audit->record($actorId, 'player', (int) $id, 'jugador:crear', null, ['name' => $name]);
        return $id;
    }

    /**
     * Ownership de un jugador (R-PERIM-02): dueño (user_id), capitán de un
     * equipo que lo contiene, u organizador de algún torneo donde el jugador
     * está inscrito o participa. El admin se resuelve en el controlador.
     */
    public function canManage(int $userId, array $player): bool
    {
        if (!empty($player['user_id']) && (int) $player['user_id'] === $userId) {
            return true;
        }

        if (empty($player['id'])) {
            return false;
        }

        $playerId = (int) $player['id'];
        $pdo = DatabaseManager::getConnection();

        $stmt = $pdo->prepare(
            'SELECT 1 FROM team_players tp
             JOIN team_captains tc ON tc.team_id = tp.team_id
             WHERE tp.player_id = ? AND tc.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$playerId, $userId]);

        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM tournaments t
             WHERE t.organizer_id = ?
               AND t.deleted_at IS NULL
               AND (
                    EXISTS (SELECT 1 FROM tournament_registrations r
                            WHERE r.tournament_id = t.id AND r.player_id = ?)
                    OR EXISTS (SELECT 1 FROM tournament_participants p
                               WHERE p.tournament_id = t.id AND p.player_id = ?)
               )
             LIMIT 1'
        );
        $stmt->execute([$userId, $playerId, $playerId]);

        return (bool) $stmt->fetchColumn();
    }

    public function update(int $actorId, int $id, array $data): ?array
    {
        $player = $this->players->find($id);
        if (!$player) {
            return null;
        }

        $this->players->update($id, array_filter([
            'name' => $data['name'] ?? null,
            'jersey_number' => $data['jersey_number'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
        ], fn($v) => $v !== null));

        $this->audit->record($actorId, 'player', $id, 'jugador:actualizar', $player);
        return $this->players->find($id);
    }

    public function delete(int $actorId, int $id): bool
    {
        $player = $this->players->find($id);
        if (!$player) {
            return false;
        }

        $this->players->delete($id);
        $this->audit->record($actorId, 'player', $id, 'jugador:eliminar', $player);
        return true;
    }
}