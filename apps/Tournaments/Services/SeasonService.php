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

    public function listar(): array
    {
        return $this->seasons->query()->orderBy('starts_at', 'DESC')->get();
    }

    public function mostrar(int $id): ?array
    {
        return $this->seasons->find($id);
    }

    public function crear(int $actorId, array $data): ?string
    {
        $nombre = trim($data['name'] ?? '');
        if ($nombre === '') {
            throw new \InvalidArgumentException('El nombre de la temporada es obligatorio');
        }

        $id = $this->seasons->create([
            'name' => $nombre,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ]);

        $this->audit->registrar($actorId, 'season', (int) $id, 'temporada:crear', null, ['name' => $nombre]);
        return $id;
    }

    public function actualizar(int $actorId, int $id, array $data): ?array
    {
        $temporada = $this->seasons->find($id);
        if (!$temporada) {
            return null;
        }

        $this->seasons->update($id, array_filter([
            'name' => $data['name'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ], fn($v) => $v !== null));

        $this->audit->registrar($actorId, 'season', $id, 'temporada:actualizar', $temporada);
        return $this->seasons->find($id);
    }

    public function eliminar(int $actorId, int $id): bool
    {
        $temporada = $this->seasons->find($id);
        if (!$temporada) {
            return false;
        }

        $this->seasons->delete($id);
        $this->audit->registrar($actorId, 'season', $id, 'temporada:eliminar', $temporada);
        return true;
    }
}