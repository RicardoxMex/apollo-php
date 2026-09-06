<?php

namespace Apollo\Core\Database;

use Apollo\Core\Database\Connection\DatabaseManager;
use PDO;

class Schema
{
    private static function getConnection(): PDO
    {
        return DatabaseManager::getConnection();
    }

    /**
     * Create a new table
     */
    public static function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        self::getConnection()->exec($blueprint->toSql());

        // SQLite no soporta INDEX inline en CREATE TABLE: se crean aparte
        foreach ($blueprint->getAdditionalIndexSql() as $indexSql) {
            self::getConnection()->exec($indexSql);
        }
    }

    /**
     * Drop table if exists
     */
    public static function dropIfExists(string $table): void
    {
        $sql = "DROP TABLE IF EXISTS `{$table}`";
        self::getConnection()->exec($sql);
    }

    /**
     * Check if table exists
     */
    public static function hasTable(string $table): bool
    {
        $connection = self::getConnection();

        if (DatabaseManager::driver() === 'sqlite') {
            $stmt = $connection->prepare(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?"
            );
            $stmt->execute([$table]);
        } else {
            $stmt = $connection->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
        }

        return $stmt->fetch() !== false;
    }
}