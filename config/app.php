<?php
// config/app.php

return [
    'name' => 'Apollo API Framework',
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    
    'apps' => [
        'enabled' => ['users', 'products'],
        'autoload' => true,
    ],
];