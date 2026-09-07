<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apps\Tournaments\Services\AuditLogService;

class AuditLogController extends Controller
{
    public function __construct(Container $container, private AuditLogService $logs)
    {
        parent::__construct($container);
    }

    /**
     * Listado de auditoría (solo lectura, requiere auth).
     */
    public function index()
    {
        try {
            $result = $this->logs->listar(
                $this->request->query('entity_type'),
                $this->request->query('entity_id') !== null ? (int) $this->request->query('entity_id') : null,
                (int) $this->request->query('perPage', 25),
                (int) $this->request->query('page', 1),
            );
            return $this->json(['success' => true, ...$result]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo listar la auditoría', 'message' => $e->getMessage()], 500);
        }
    }
}