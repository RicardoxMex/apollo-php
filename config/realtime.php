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
    | REALTIME_DRIVER: auto | redis | local
    |   auto  → detecta Redis (ping); si no, usa LocalEventBus
    |   redis → exige Redis (error claro si no está)
    |   local → nunca intenta conectarse a Redis (una sola instancia)
    |
    */
    'driver' => env('REALTIME_DRIVER', 'auto'),

    // Servidor WebSocket
    'host' => env('REALTIME_HOST', '0.0.0.0'),
    'port' => (int) env('REALTIME_PORT', 8080),
    'max_connections' => (int) env('REALTIME_MAX_CONNECTIONS', 10000),
    'max_message_size' => (int) env('REALTIME_MAX_MESSAGE_SIZE', 8192),

    // Heartbeat
    'heartbeat_interval' => (int) env('REALTIME_HEARTBEAT_INTERVAL', 30),
    'connection_timeout' => (int) env('REALTIME_CONNECTION_TIMEOUT', 60),

    // Identidad de la aplicación (el cliente SOLO ve app_key, nunca el secreto)
    'app' => [
        'id' => env('REALTIME_APP_ID', 'apollo'),
        'key' => env('REALTIME_APP_KEY', 'app_apollo'),
        'secret' => env('REALTIME_APP_SECRET', ''),
    ],

    // Redis (opcional; el health check decide)
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => (int) env('REDIS_DATABASE', 0),
        'timeout' => 1.0, // segundos (health check rápido)
    ],

    // Canal privado por defecto solicitado por la app para auth
    'auth' => [
        // callback opcional: fn($channelName, $user, $request) => bool
        'authorize' => null,
    ],

    // Notificaciones
    'notifications' => [
        // Persistencia en base de datos (requiere migración 009)
        'database' => env('REALTIME_DB_NOTIFICATIONS', true),
    ],
];