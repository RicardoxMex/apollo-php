<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Controller;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Throwable;

/**
 * Health check público (sin auth) para monitoreo/uptime.
 *
 * GET /api/health:
 *  - 200 { status: "ok",       db: true,  time }  → app + DB operativos
 *  - 503 { status: "degraded", db: false, time }  → la app responde pero la BD falla
 *
 * No expone detalles del error (mensajes/credenciales) a propósito.
 */
class HealthController extends Controller
{
    public function index(Request $request): Response
    {
        $db = false;

        try {
            $db = (bool) DatabaseManager::getConnection()->query('SELECT 1')->fetchColumn();
        } catch (Throwable) {
            $db = false;
        }

        return Response::json([
            'status' => $db ? 'ok' : 'degraded',
            'db' => $db,
            'time' => gmdate('c'),
        ], $db ? 200 : 503);
    }
}
