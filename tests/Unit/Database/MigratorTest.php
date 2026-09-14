<?php

namespace Tests\Unit\Database;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Database\Migrator;
use Tests\SqliteTestCase;
use PDO;

/**
 * Migrator sobre SQLite :memory: con migraciones reales en un directorio
 * temporal (Schema es driver-aware, así que funciona sin MySQL).
 * Requiere extension=pdo_sqlite (se omite automáticamente si no está cargada).
 */
class MigratorTest extends SqliteTestCase
{
    private string $migrationsDir;
    private Migrator $migrator;

    protected static function sqliteFreshPerTest(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrationsDir = sys_get_temp_dir() . '/apollo-migrator-' . bin2hex(random_bytes(4));
        mkdir($this->migrationsDir . '/migrations', 0775, true);

        $this->writeMigration('001_create_mig_a.php', 'mig_a');
        $this->writeMigration('002_create_mig_b.php', 'mig_b');

        $this->migrator = new Migrator($this->migrationsDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->migrationsDir);
        parent::tearDown();
    }

    private function writeMigration(string $file, string $table): void
    {
        $code = <<<PHP
<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('{$table}', function (\$table) {
            \$table->id();
            \$table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
        file_put_contents($this->migrationsDir . '/migrations/' . $file, $code);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->rrmdir($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    private function tables(): array
    {
        $tables = self::$pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);

        return $tables;
    }

    public function test_migrate_applies_pending_in_order(): void
    {
        $run = $this->migrator->migrate(self::$pdo);

        $this->assertSame(['001_create_mig_a.php', '002_create_mig_b.php'], $run);
        $this->assertSame(['mig_a', 'mig_b', 'migrations'], $this->tables());
        $this->assertSame([], $this->migrator->pending(self::$pdo));

        // Todo queda en el batch 1
        $batches = self::$pdo->query('SELECT DISTINCT batch FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([1], array_map('intval', $batches));
    }

    public function test_migrate_twice_is_noop(): void
    {
        $this->migrator->migrate(self::$pdo);

        $this->assertSame([], $this->migrator->migrate(self::$pdo));
    }

    public function test_new_migration_goes_to_new_batch(): void
    {
        $this->migrator->migrate(self::$pdo);

        // Aparece una migración nueva en el disco
        $this->writeMigration('003_create_mig_c.php', 'mig_c');

        $this->assertSame(['003_create_mig_c.php'], $this->migrator->pending(self::$pdo));

        $run = $this->migrator->migrate(self::$pdo);
        $this->assertSame(['003_create_mig_c.php'], $run);

        // Solo la nueva se aplicó, y en el batch 2
        $batch = self::$pdo->query("SELECT batch FROM migrations WHERE migration = '003_create_mig_c.php'")->fetchColumn();
        $this->assertSame(2, (int) $batch);

        $this->assertContains('mig_c', $this->tables());
    }

    public function test_rollback_last_reverts_only_last_batch(): void
    {
        $this->migrator->migrate(self::$pdo);
        $this->writeMigration('003_create_mig_c.php', 'mig_c');
        $this->migrator->migrate(self::$pdo);

        $reverted = $this->migrator->rollbackLast(self::$pdo);

        $this->assertSame(['003_create_mig_c.php'], $reverted);
        $this->assertNotContains('mig_c', $this->tables());
        $this->assertContains('mig_a', $this->tables());
        $this->assertContains('mig_b', $this->tables());

        // La migración vuelve a estar pendiente (se puede re-aplicar)
        $this->assertSame(['003_create_mig_c.php'], $this->migrator->pending(self::$pdo));
    }

    public function test_rollback_last_with_nothing_is_noop(): void
    {
        $this->assertSame([], $this->migrator->rollbackLast(self::$pdo));
    }

    public function test_rollback_all_reverts_everything(): void
    {
        $this->migrator->migrate(self::$pdo);
        $this->writeMigration('003_create_mig_c.php', 'mig_c');
        $this->migrator->migrate(self::$pdo);

        $reverted = $this->migrator->rollbackAll(self::$pdo);

        sort($reverted);
        $this->assertSame(
            ['001_create_mig_a.php', '002_create_mig_b.php', '003_create_mig_c.php'],
            $reverted
        );

        // Sin tablas de usuario (solo queda el tracking)
        $this->assertSame(['migrations'], $this->tables());
        $this->assertSame([], $this->migrator->applied(self::$pdo));
    }

    public function test_files_are_sorted(): void
    {
        $this->assertSame(
            ['001_create_mig_a.php', '002_create_mig_b.php'],
            $this->migrator->files()
        );
    }
}