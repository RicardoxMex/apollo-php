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

    public function listar(?string $q = null): array
    {
        if (!empty($q)) {
            return $this->players->search($q);
        }
        return $this->players->query()->orderBy('name', 'ASC')->get();
    }

    public function mostrar(int $id): ?array
    {
        return $this->players->find($id);
    }

    public function crear(int $actorId, array $data): ?string
    {
        $nombre = trim($data['name'] ?? '');
        if ($nombre === '') {
            throw new \InvalidArgumentException('El nombre del jugador es obligatorio');
        }

        $id = $this->players->create([
            'user_id' => !empty($data['user_id']) ? (int) $data['user_id'] : null,
            'name' => $nombre,
            'jersey_number' => $data['jersey_number'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
        ]);

        $this->audit->registrar($actorId, 'player', (int) $id, 'jugador:crear', null, ['name' => $nombre]);
        return $id;
    }

    public function actualizar(int $actorId, int $id, array $data): ?array
    {
        $jugador = $this->players->find($id);
        if (!$jugador) {
            return null;
        }

        $this->players->update($id, array_filter([
            'name' => $data['name'] ?? null,
            'jersey_number' => $data['jersey_number'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
        ], fn($v) => $v !== null));

        $this->audit->registrar($actorId, 'player', $id, 'jugador:actualizar', $jugador);
        return $this->players->find($id);
    }

    public function eliminar(int $actorId, int $id): bool
    {
        $jugador = $this->players->find($id);
        if (!$jugador) {
            return false;
        }

        $this->players->delete($id);
        $this->audit->registrar($actorId, 'player', $id, 'jugador:eliminar', $jugador);
        return true;
    }
}