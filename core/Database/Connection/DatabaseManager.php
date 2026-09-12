<?php
// core/Database/Connection/DatabaseManager.php

namespace Apollo\Core\Database\Connection;

use Apollo\Core\Database\Drivers\DriverInterface;
use PDO;

class DatabaseManager {
    private static ?PDO $connection = null;
    private static array $config = [];
    
    public static function setConfig(array $config): void {
        self::$config = $config;
    }

    public static function getConfig(): array {
        return self::$config;
    }

    /**
     * Driver activo (para que Schema/Blueprint generen DDL compatible)
     */
    public static function driver(): string {
        return self::$config['driver'] ?? self::$config['connection'] ?? 'mysql';
    }

    /**
     * Lista las tablas de usuario de la base de datos activa (excluye tablas
     * internas de SQLite como sqlite_sequence). Driver-aware.
     *
     * MySQL:  information_schema.tables (columna TABLE_NAME, mayúsculas).
     * SQLite: sqlite_master.
     *
     * @return string[]
     */
    public static function listTables(): array
    {
        $pdo = self::getConnection();

        if (self::driver() === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        }

        // MySQL: information_schema.tables tiene la columna TABLE_NAME (mayúsculas).
        // Usamos fetchColumn(0) para evitar depender del case del nombre de columna.
        $stmt = $pdo->query('SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE()');
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public static function getConnection(): PDO {
        if (self::$connection === null) {
            self::createConnection();
        }
        
        return self::$connection;
    }
    
    private static function createConnection(): void {
        $driver = self::$config['connection'] ?? 'mysql';
        $driverInstance = ConnectionFactory::create($driver);
        
        self::$connection = $driverInstance->connect([
            'host' => self::$config['host'] ?? '127.0.0.1',
            'port' => self::$config['port'] ?? 3306,
            'database' => self::$config['database'] ?? '',
            'username' => self::$config['username'] ?? 'root',
            'password' => self::$config['password'] ?? '',
            'charset' => self::$config['charset'] ?? 'utf8mb4',
            'collation' => self::$config['collation'] ?? 'utf8mb4_unicode_ci'
        ]);
    }
    
    public static function disconnect(): void {
        self::$connection = null;
    }
    
    public static function beginTransaction(): bool {
        return self::getConnection()->beginTransaction();
    }
    
    public static function commit(): bool {
        return self::getConnection()->commit();
    }
    
    public static function rollback(): bool {
        return self::getConnection()->rollBack();
    }
}