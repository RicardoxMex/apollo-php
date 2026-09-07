<?php

namespace Apps\Tournaments\Repositories;

use Apollo\Core\Database\Repository\BaseRepository;

class AuditLogRepository extends BaseRepository
{
    protected string $table = 'audit_logs';

    public function registrar(int|string|null $actorId, string $entityType, int|string $entityId, string $accion, ?array $antes = null, ?array $despues = null, ?string $ip = null, ?string $userAgent = null): ?string
    {
        return $this->create([
            'actor_id' => $actorId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $accion,
            'before_data' => $antes !== null ? json_encode($antes) : null,
            'after_data' => $despues !== null ? json_encode($despues) : null,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    public function filtrar(?string $entityType = null, ?int $entityId = null, int $perPage = 25, int $page = 1): array
    {
        $query = $this->builder();
        if ($entityType) {
            $query->where('entity_type', $entityType);
        }
        if ($entityId) {
            $query->where('entity_id', $entityId);
        }

        $total = (int) $query->count();
        $items = $query->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return [
            'data' => $items,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }
}