<?php

namespace Tests\Unit\Database;

use Apollo\Core\Database\Connection\DatabaseManager;
use Tests\SqliteTestCase;

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
class DatabaseManagerListTablesTest extends SqliteTestCase
{
    protected static function sqliteFreshPerTest(): bool
    {
        return true;
    }

    public static function setUpBeforeClass(): void
    {
        $_ENV['APP_DEBUG'] = false;
        $_ENV['JWT_SECRET_KEY'] = 'unit-test-secret-0123456789abcdef';
        $_ENV['JWT_ALGORITHM'] = 'HS256';

        parent::setUpBeforeClass();
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
        // (el nombre está reservado: CREATE TABLE sqlite_* falla en SQLite >= 3.22)
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        // El primer INSERT dispara la creación de sqlite_sequence
        $pdo->exec('INSERT INTO users DEFAULT VALUES');

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
