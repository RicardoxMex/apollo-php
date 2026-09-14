<?php
// core/Console/Commands/DatabaseSetupCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;
use Apollo\Core\Database\Connection\DatabaseManager;
use PDO;
use PDOException;
use RuntimeException;

class DatabaseSetupCommand extends Command
{
    protected string $signature = 'db:setup';
    protected string $description = 'Drop all tables and run all migrations (migrate:fresh)';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        try {
            $pdo = DatabaseManager::getConnection();
            $this->info('✓ Database connection established (' . DatabaseManager::driver() . ')');

            $this->line("\nDropping tables...");
            $this->dropAllTables($pdo);

            $this->line("\nRunning migrations...");
            $migrationFiles = glob($this->app->make('path.database') . '/migrations/*.php');

            if (!$migrationFiles) {
                $this->warn('No migration files found.');
                return 0;
            }

            sort($migrationFiles);

            foreach ($migrationFiles as $file) {
                $this->line('  Running: ' . basename($file));
                $migration = require $file;
                $migration->up();
                \Apollo\Core\Database\Migrator::record($pdo, basename($file), now());
                $this->info('  ✓ ' . basename($file) . ' executed');
            }

            $this->line();
            $this->info('✅ Database configured successfully!');
            $this->line('Next: php apollo db:seed');

            return 0;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }

    private function dropAllTables(PDO $pdo): void
    {
        $tables = DatabaseManager::listTables();

        if (empty($tables)) {
            $this->line('  No tables to drop.');
            return;
        }

        for ($attempt = 0; $attempt < 20 && !empty($tables); $attempt++) {
            $remaining = [];
            $dropped = 0;

            foreach ($tables as $table) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                    $this->line("  ✓ Table {$table} dropped");
                    $dropped++;
                } catch (PDOException $e) {
                    $remaining[] = $table;
                }
            }

            if ($dropped === 0) {
                break;
            }

            $tables = $remaining;
        }

        if (!empty($tables)) {
            throw new RuntimeException('Could not drop tables: ' . implode(', ', $tables));
        }
    }
}