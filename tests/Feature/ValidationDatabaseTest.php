<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Reglas de validación respaldadas por base de datos (unique/exists) sobre
 * SQLite :memory:. Requiere extension=pdo_sqlite (se omite si no está cargada).
 */
class ValidationDatabaseTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite no disponible: habilita extension=pdo_sqlite en php.ini');
        }

        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // BD fresca (evita tablas de otros tests con la misma config :memory:)
        DatabaseManager::disconnect();
        self::$pdo = DatabaseManager::getConnection();

        self::$pdo->exec(
            'CREATE TABLE validation_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                role_id INTEGER NULL
            )'
        );

        self::$pdo->exec(
            "INSERT INTO validation_users (email, role_id) VALUES ('dupe@example.com', 1)"
        );
    }

    public function test_unique_passes_when_value_is_available(): void
    {
        $this->assertTrue(
            Validator::make(['email' => 'nuevo@example.com'], ['email' => 'unique:validation_users,email'])->passes()
        );
    }

    public function test_unique_fails_on_duplicate(): void
    {
        $v = Validator::make(['email' => 'dupe@example.com'], ['email' => 'unique:validation_users,email']);

        $this->assertFalse($v->passes());
        $this->assertSame(['El valor de email ya está en uso.'], $v->errors()['email']);
    }

    public function test_unique_ignores_given_row_id(): void
    {
        // Edición: el propio registro se excluye con el tercer parámetro (id de excepción)
        $this->assertTrue(
            Validator::make(
                ['email' => 'dupe@example.com'],
                ['email' => 'unique:validation_users,email,1']
            )->passes()
        );
    }

    public function test_exists_passes_and_fails(): void
    {
        $this->assertTrue(
            Validator::make(['role_id' => 1], ['role_id' => 'exists:validation_users,role_id'])->passes()
        );
        $this->assertFalse(
            Validator::make(['role_id' => 999], ['role_id' => 'exists:validation_users,role_id'])->passes()
        );
    }

    public function test_unique_requires_table(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Validator::make(['email' => 'a@b.co'], ['email' => 'unique:'])->passes();
    }
}