<?php

namespace Apps\Tournaments\Repositories;

use Apollo\Core\Database\Repository\BaseRepository;

class AuditLogRepository extends BaseRepository
{
    protected string $table = 'audit_logs';

    public function record(int|string|null $actorId, string $entityType, int|string $entityId, string $action, ?array $before = null, ?array $after = null, ?string $ip = null, ?string $userAgent = null): ?string
    {
        return $this->create([
            'actor_id' => $actorId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_data' => $before !== null ? json_encode($before) : null,
            'after_data' => $after !== null ? json_encode($after) : null,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    public function filter(?string $entityType = null, ?int $entityId = null, int $perPage = 25, int $page = 1): array
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