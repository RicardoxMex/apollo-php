<?php

namespace Apps\Tournaments\Services;

use Apps\Tournaments\Repositories\PlayerRepository;

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