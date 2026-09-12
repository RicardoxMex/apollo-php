<?php

namespace Tests\Unit\Database;

use Apollo\Core\Application;
use Apollo\Core\Database\Connection\DatabaseManager;
use PHPUnit\Framework\TestCase;

/**
 * Verifica que DatabaseManager::listTables() es driver-aware y devuelve los
 * nombres correctos de las tablas de usuario (excluye tablas internas de SQLite).
 *
 * Bug original: en MySQL, information_schema.tables devuelve la columna TABLE_NAME
 * (mayúsculas). El código previo usaba `table_name` (minúsculas) → `Undefined array key`
 * → `array_map` producía strings vacíos → el drop fallaba con "Could not drop tables: ".
 *
 * Fix: usar fetchColumn(0) y centralizar la lógica en DatabaseManager::listTables().
 */
class DatabaseManagerListTablesTest extends TestCase
{
    private static ?Application $app = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite requerido para listTables()');
        }

        $_ENV['APP_DEBUG'] = false;
        $_ENV['JWT_SECRET_KEY'] = 'unit-test-secret-0123456789abcdef';
        $_ENV['JWT_ALGORITHM'] = 'HS256';

        self::$app = new Application(dirname(__DIR__, 2));
        self::$app->make('config');
    }

    protected function setUp(): void
    {
        // BD fresca por test
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
        DatabaseManager::disconnect();
    }

    public function test_list_tables_returns_empty_when_no_tables(): void
    {
        $tables = DatabaseManager::listTables();
        $this->assertSame([], $tables);
    }

    public function test_list_tables_returns_user_tables(): void
    {
        $pdo = DatabaseManager::getConnection();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE tournaments (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE notifications (id TEXT PRIMARY KEY)');

        $tables = DatabaseManager::listTables();

        sort($tables);
        $this->assertSame(['notifications', 'tournaments', 'users'], $tables);
    }

    public function test_list_tables_excludes_sqlite_internal_tables(): void
    {
        $pdo = DatabaseManager::getConnection();
        // SQLite crea automáticamente sqlite_sequence cuando hay AUTOINCREMENT
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        // Forzar creación de sqlite_sequence (normalmente SQLite la crea sola
        // al primer INSERT; aquí la creamos explícitamente para el test).
        $pdo->exec('CREATE TABLE sqlite_sequence (name TEXT, seq INTEGER)');

        $tables = DatabaseManager::listTables();

        $this->assertNotContains('sqlite_sequence', $tables, 'sqlite_sequence debe excluirse');
        $this->assertContains('users', $tables);
    }

    public function test_list_tables_returns_empty_strings_filtered_out(): void
    {
        // Regression test: si el método volviera a devolver strings vacíos
        // (bug original), este test falla.
        $pdo = DatabaseManager::getConnection();
        $pdo->exec('CREATE TABLE real_table (id INTEGER PRIMARY KEY)');

        $tables = DatabaseManager::listTables();

        foreach ($tables as $t) {
            $this->assertNotSame('', $t, "listTables() no debe devolver strings vacíos (bug original)");
        }
    }
}
