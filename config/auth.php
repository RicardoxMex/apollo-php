<?php
return [
    "jwt" => [
        'secret_key' => env('JWT_SECRET_KEY'),
        'algorithm' => env('JWT_ALGORITHM', 'HS256'),
        'issuer' => env('JWT_ISSUER', 'tu-api.com'),
        'audience' => env('JWT_AUDIENCE', 'api-client'),
        'expiry' => (int) env('JWT_EXPIRY', 3600),
    ],
    'rate_limit' => [
        'max_attempts' => (int) env('RATE_LIMIT_MAX_ATTEMPTS', 5),
        'window' => (int) env('RATE_LIMIT_WINDOW', 900) // 15 minutos
    ]
];