<?php

// Resolución del driver con alias estilo Laravel: MAIL_MAILER (o MAIL_DRIVER).
$mailDriver = env('MAIL_MAILER', env('MAIL_DRIVER', 'log'));

// Esquema de cifrado: MAIL_SCHEME explícito (tls/ssl/none) o 'null' → auto por
// puerto (465 → ssl implícito; 587/2525 → tls STARTTLS). Fallback MAIL_ENCRYPTION.
$mailScheme = env('MAIL_SCHEME');
if (!is_string($mailScheme) || !in_array(strtolower($mailScheme), ['tls', 'ssl', 'none'], true)) {
    $mailScheme = ((int) env('MAIL_PORT', 587)) === 465 ? 'ssl' : 'tls';
}

return [
    /*
    |--------------------------------------------------------------------------
    | Correo (módulo core/Mail)
    |--------------------------------------------------------------------------
    |
    | driver: 'log' (dev/test: escribe en MAIL_LOG_PATH) | 'smtp' (producción).
    | El envío nunca rompe la petición: ante fallo reintenta 1 vez y loguea
    | (D1). El remitente por defecto se resuelve desde 'from'.
    |
    */
    'driver' => $mailDriver,
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@torneomaster.app'),
        'name' => env('MAIL_FROM_NAME', 'TorneoMaster'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMTP (usado cuando driver = smtp)
    |--------------------------------------------------------------------------
    */
    'smtp' => [
        'host' => env('MAIL_HOST', 'localhost'),
        'port' => (int) env('MAIL_PORT', 587),
        'username' => env('MAIL_USERNAME', ''),
        'password' => env('MAIL_PASSWORD', ''),
        'encryption' => env('MAIL_ENCRYPTION', $mailScheme),   // tls | ssl | none
        'verify_peer' => env('MAIL_VERIFY_PEER', true),
        'timeout' => (int) env('MAIL_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Driver log (dev/test)
    |--------------------------------------------------------------------------
    */
    'log_path' => env('MAIL_LOG_PATH', 'runtime/logs/mail'),

    /*
    |--------------------------------------------------------------------------
    | URL pública del frontend (enlaces de verificación / reset)
    |--------------------------------------------------------------------------
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Robustez: reintentos adicionales al primer intento
    |--------------------------------------------------------------------------
    */
    'retry' => (int) env('MAIL_RETRY', 1),
];