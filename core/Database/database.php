<?php
// core/Database/database.php

namespace Apollo\Core\Database;

use Apollo\Core\Database\Connection\DatabaseManager;

function initDatabase(): void {
    DatabaseManager::setConfig([
        'connection' => env('DB_CONNECTION', 'mysql'),
        'driver' => env('DB_DRIVER', 'mysql'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', ''),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci')
    ]);
}