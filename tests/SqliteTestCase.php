<?php

namespace Tests;

use Apollo\Core\Database\Connection\DatabaseManager;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Base para suites que necesitan SQLite :memory:.
 *
 * Elimina la repetición del patrón
 * "skip si falta pdo_sqlite + Application + config + DatabaseManager sqlite
 * + (migraciones)" que cada suite SQLite copiaba.
 *
 * - Se omite la clase automáticamente si ext-pdo_sqlite no está cargada.
 * - Bootea Application + config (sin providers) y configura DatabaseManager
 *   con sqlite :memory: y BD fresca.
 * - migrarBD() => true: ejecuta database/migrations/*.php una vez por clase.
 * - sqliteFreshPerTest() => true: BD fresca en cada test (setUp).
 */
abstract class SqliteTestCase extends TestCase
{
    protected static ?PDO $pdo = null;

    protected static function migrarBD(): bool
    {
        return false;
    }

    protected static function sqliteFreshPerTest(): bool
    {
        return false;
    }

    protected static function bootSqlite(): PDO
    {
        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
            'host' => '',
            'port' => 0,
            'username' => '',
            'password' => '',
            'charset' => '',
            'collation' => '',
        ]);

        // BD fresca (evita tablas de otros tests con la misma config :memory:)
        DatabaseManager::disconnect();

        return DatabaseManager::getConnection();
    }

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite no disponible: habilita extension=pdo_sqlite en php.ini');
        }

        // Aislar del estado global: bootear config (sin providers para no
        // pisar la config sqlite). Los helpers del core (now(), etc.) llegan
        // por autoload de composer.
        new \Apollo\Core\Application(dirname(__DIR__));
        \app('config');

        self::$pdo = self::bootSqlite();

        if (static::migrarBD()) {
            $files = glob(dirname(__DIR__) . '/database/migrations/*.php');
            sort($files);

            foreach ($files as $file) {
                $migration = require $file;
                $migration->up();
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (static::sqliteFreshPerTest()) {
            self::$pdo = self::bootSqlite();
        }
    }
}