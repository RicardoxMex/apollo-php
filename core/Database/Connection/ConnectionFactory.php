<?php
// core/Database/Connection/ConnectionFactory.php

namespace Apollo\Core\Database\Connection;

use Apollo\Core\Database\Drivers\{
    DriverInterface,
    MySQLDriver
};
use InvalidArgumentException;

class ConnectionFactory {
    public static function create(string $driver): DriverInterface {
        return match(strtolower($driver)) {
            'mysql', 'mariadb' => new MySQLDriver(),
            default => throw new InvalidArgumentException(
                "Driver [{$driver}] no soportado. Drivers disponibles: mysql"
            )
        };
    }
}