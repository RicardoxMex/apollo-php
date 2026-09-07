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
     * Registra una acción de auditoría. El Request es opcional (IP/user-agent).
     */
    public function registrar(int|string|null $actorId, string $entidad, int|string $entidadId, string $accion, ?array $antes = null, ?array $despues = null, ?Request $request = null): void
    {
        $ip = null;
        $userAgent = null;
        if ($request) {
            $ip = $request->ip() ?? null;
            $userAgent = $request->userAgent();
        }

        $this->logs->registrar($actorId, $entidad, $entidadId, $accion, $antes, $despues, $ip, $userAgent);
    }

    public function listar(?string $entidad = null, ?int $entidadId = null, int $perPage = 25, int $page = 1): array
    {
        return $this->logs->filtrar($entidad, $entidadId, $perPage, $page);
    }
}