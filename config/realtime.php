<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Realtime / WebSockets / Notificaciones
    |--------------------------------------------------------------------------
    |
    | Módulo opcional del framework. Inerte hasta que se usa: el servidor
    | (realtime:start) y el broker solo se activan al ser invocados.
    |
    | Resolución: el config acepta los nombres del spec del usuario
    | (WEBSOCKET_*) con fallback a los nombres legacy (REALTIME_*).
    |
    | REALTIME_DRIVER: auto | redis | local
    |   auto  → detecta Redis (ping); si no, usa LocalEventBus
    |   redis → exige Redis (error claro si no está)
    |   local → nunca intenta conectarse a Redis (una sola instancia)
    |
    */
    'driver' => env('REALTIME_DRIVER', env('WEBSOCKET_DRIVER', 'auto')),

    // --- Servidor WebSocket (Workerman) ---------------------------------
    'enabled' => (bool) env('WEBSOCKET_ENABLED', env('REALTIME_ENABLED', true)),
    'host' => env('WEBSOCKET_HOST', env('REALTIME_HOST', '127.0.0.1')),
    'port' => (int) env('WEBSOCKET_PORT', env('REALTIME_PORT', 8080)),
    'public_url' => env('WEBSOCKET_PUBLIC_URL', env('REALTIME_PUBLIC_URL', null)),
    'max_connections' => (int) env('WEBSOCKET_MAX_CONNECTIONS', env('REALTIME_MAX_CONNECTIONS', 10000)),
    'max_message_size' => (int) env('WEBSOCKET_MAX_MESSAGE_SIZE', env('REALTIME_MAX_MESSAGE_SIZE', 8192)),

    // --- Polling server-side de notificaciones (D4-revisado) -------------
    // El servidor consulta la tabla `notifications` cada N segundos para
    // entregar a las conexiones autenticadas (cross-platform, sin IPC).
    'poll_interval' => (int) env('WEBSOCKET_POLL_INTERVAL', 1),

    // --- Heartbeat -------------------------------------------------------
    'heartbeat_interval' => (int) env('WEBSOCKET_HEARTBEAT_INTERVAL', env('REALTIME_HEARTBEAT_INTERVAL', 30)),
    'connection_timeout' => (int) env('WEBSOCKET_CONNECTION_TIMEOUT', env('REALTIME_CONNECTION_TIMEOUT', 60)),

    // --- Identidad de la aplicación (el cliente SOLO ve app_key) --------
    'app' => [
        'id' => env('WEBSOCKET_APP_ID', env('REALTIME_APP_ID', 'apollo')),
        'key' => env('WEBSOCKET_APP_KEY', env('REALTIME_APP_KEY', 'app_apollo')),
        'secret' => env('WEBSOCKET_APP_SECRET', env('REALTIME_APP_SECRET', '')),
    ],

    // --- WSS (si no se termina TLS en Nginx) -----------------------------
    'ssl' => [
        'enabled' => (bool) env('WEBSOCKET_SSL_ENABLED', false),
        'local_cert' => env('WEBSOCKET_SSL_LOCAL_CERT', null),
        'local_key' => env('WEBSOCKET_SSL_LOCAL_PKEY', null),
    ],

    // --- Redis (opcional; el health check decide) -----------------------
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => (int) env('REDIS_DATABASE', 0),
        'timeout' => 1.0,
    ],

    // --- Autorización de canales (callback opcional) --------------------
    'auth' => [
        'authorize' => null,
    ],

    // --- Notificaciones -------------------------------------------------
    'notifications' => [
        'database' => (bool) env('WEBSOCKET_DB_NOTIFICATIONS', env('REALTIME_DB_NOTIFICATIONS', true)),
    ],
];
