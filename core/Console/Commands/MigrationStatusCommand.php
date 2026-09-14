<?php
// core/Console/Commands/MigrationStatusCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;
use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Database\Migrator;

/**
 * Estado de migraciones: lista database/migrations/*.php y marca cuáles están
 * aplicadas (tabla de control 'migrations' que escriben migrate, db:setup y
 * setup_database.php).
 */
class MigrationStatusCommand extends Command
{
    protected string $signature = 'migrate:status';
    protected string $description = 'Show applied vs pending migrations';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        try {
            $pdo = DatabaseManager::getConnection();
            $migrator = new Migrator($this->app->make('path.database'));

            $applied = $migrator->applied($pdo);
            $files = $migrator->files();

            if (empty($files)) {
                $this->warn('No hay migraciones en database/migrations.');
                return 0;
            }

            $rows = [];
            foreach ($files as $name) {
                $isApplied = in_array($name, $applied, true);
                $rows[] = [
                    $isApplied ? 'applied' : 'pending',
                    $name,
                    $isApplied ? 'sí' : '—',
                ];
            }

            $this->table(['Estado', 'Migración', 'Aplicada'], $rows);

            $appliedCount = count(array_filter($rows, fn ($r) => $r[0] === 'applied'));
            $pendingCount = count($rows) - $appliedCount;
            $this->line();
            $this->info("{$appliedCount} aplicada(s), {$pendingCount} pendiente(s).");

            return 0;
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}