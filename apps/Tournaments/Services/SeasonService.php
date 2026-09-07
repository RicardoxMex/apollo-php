<?php

namespace Apps\Tournaments\Services;

use Apps\Tournaments\Repositories\SeasonRepository;

class SeasonService
{
    public function __construct(
        private SeasonRepository $seasons,
        private AuditLogService $audit,
    ) {
    }

    public function list(): array
    {
        return $this->seasons->query()->orderBy('starts_at', 'DESC')->get();
    }

    public function show(int $id): ?array
    {
        return $this->seasons->find($id);
    }

    public function create(int $actorId, array $data): ?string
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre de la temporada es obligatorio');
        }

        $id = $this->seasons->create([
            'name' => $name,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ]);

        $this->audit->record($actorId, 'season', (int) $id, 'temporada:crear', null, ['name' => $name]);
        return $id;
    }

    public function update(int $actorId, int $id, array $data): ?array
    {
        $season = $this->seasons->find($id);
        if (!$season) {
            return null;
        }

        $this->seasons->update($id, array_filter([
            'name' => $data['name'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ], fn($v) => $v !== null));

        $this->audit->record($actorId, 'season', $id, 'temporada:actualizar', $season);
        return $this->seasons->find($id);
    }

    public function delete(int $actorId, int $id): bool
    {
        $season = $this->seasons->find($id);
        if (!$season) {
            return false;
        }

        $this->seasons->delete($id);
        $this->audit->record($actorId, 'season', $id, 'temporada:eliminar', $season);
        return true;
    }
}