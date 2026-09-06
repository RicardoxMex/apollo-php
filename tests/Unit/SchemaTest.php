<?php

namespace Tests\Unit;

use Apollo\Core\Database\Blueprint;
use Apollo\Core\Database\Connection\DatabaseManager;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    private array $previousConfig;

    protected function setUp(): void
    {
        $this->previousConfig = DatabaseManager::getConfig();
    }

    protected function tearDown(): void
    {
        DatabaseManager::setConfig($this->previousConfig);
    }

    private function sqlFor(string $driver, callable $callback): string
    {
        DatabaseManager::setConfig([
            'connection' => $driver,
            'driver' => $driver,
            'database' => ':memory:',
        ]);

        $blueprint = new Blueprint('test_table');
        $callback($blueprint);

        return $blueprint->toSql();
    }

    public function test_mysql_ddl_keeps_engine_and_types(): void
    {
        $sql = $this->sqlFor('mysql', fn($t) => $t->id());

        $this->assertStringContainsString('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
    }

    public function test_sqlite_id_uses_integer_autoincrement(): void
    {
        $sql = $this->sqlFor('sqlite', fn($t) => $t->id());

        $this->assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $this->assertStringNotContainsString('ENGINE=InnoDB', $sql);
    }

    public function test_sqlite_enum_and_json_become_text(): void
    {
        $sql = $this->sqlFor('sqlite', function ($t) {
            $t->enum('status', ['a', 'b']);
            $t->json('meta');
        });

        $this->assertStringContainsString('`status` TEXT NOT NULL', $sql);
        $this->assertStringContainsString('`meta` TEXT NOT NULL', $sql);
        $this->assertStringNotContainsString('ENUM', $sql);
        $this->assertStringNotContainsString('JSON', $sql);
    }

    public function test_sqlite_timestamps_have_no_on_update(): void
    {
        $sql = $this->sqlFor('sqlite', fn($t) => $t->timestamps());

        $this->assertStringNotContainsString('ON UPDATE', $sql);
        $this->assertStringContainsString('DATETIME DEFAULT CURRENT_TIMESTAMP', $sql);
    }

    public function test_sqlite_foreign_id_uses_integer(): void
    {
        $sql = $this->sqlFor('sqlite', fn($t) => $t->foreignId('user_id'));

        $this->assertStringContainsString('`user_id` INTEGER NOT NULL', $sql);
        $this->assertStringNotContainsString('BIGINT', $sql);
    }

    public function test_sqlite_unique_uses_constraint_syntax(): void
    {
        $sql = $this->sqlFor('sqlite', fn($t) => $t->unique(['role_id', 'permission_id']));

        $this->assertStringContainsString('CONSTRAINT', $sql);
        $this->assertStringNotContainsString('UNIQUE KEY', $sql);
    }
}