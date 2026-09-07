<?php

namespace Apps\Tournaments\Services;

use Apps\Tournaments\Repositories\AuditLogRepository;
use Apollo\Core\Http\Request;

class AuditLogService
{
    public function __construct(private AuditLogRepository $logs)
    {
    }

    /**
     * Records an audit action. The Request is optional (IP/user-agent).
     */
    public function record(int|string|null $actorId, string $entityType, int|string $entityId, string $action, ?array $before = null, ?array $after = null, ?Request $request = null): void
    {
        $ip = null;
        $userAgent = null;
        if ($request) {
            $ip = $request->ip() ?? null;
            $userAgent = $request->userAgent();
        }

        $this->logs->record($actorId, $entityType, $entityId, $action, $before, $after, $ip, $userAgent);
    }

    public function list(?string $entityType = null, ?int $entityId = null, int $perPage = 25, int $page = 1): array
    {
        return $this->logs->filter($entityType, $entityId, $perPage, $page);
    }
}