<?php

// Alias estilo Laravel: MAIL_MAILER (preferido) o MAIL_DRIVER.
// Cifrado: MAIL_SCHEME / MAIL_ENCRYPTION con auto por puerto (465→ssl, 587→tls).
$driver = env('MAIL_MAILER', env('MAIL_DRIVER', 'log'));
$port = (int) env('MAIL_PORT', 587);
$scheme = env('MAIL_SCHEME', env('MAIL_ENCRYPTION'));
$scheme = is_string($scheme) ? strtolower(trim($scheme)) : $scheme;
$encryption = ($scheme === null || $scheme === '' || $scheme === 'null')
    ? ($port === 465 ? 'ssl' : 'tls')
    : $scheme;

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
    'driver' => $driver,
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
        'port' => $port,
        'username' => env('MAIL_USERNAME', ''),
        'password' => env('MAIL_PASSWORD', ''),
        'encryption' => $encryption,   // tls | ssl | none
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