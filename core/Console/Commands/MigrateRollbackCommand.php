<?php
// core/Console/Commands/MigrateRollbackCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;
use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Database\Migrator;

/**
 * Deshace el último batch de migraciones (down() + limpieza del tracking).
 */
class MigrateRollbackCommand extends Command
{
    protected string $signature = 'migrate:rollback';
    protected string $description = 'Rollback the last migration batch';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        try {
            $migrator = new Migrator($this->app->make('path.database'));
            $reverted = $migrator->rollbackLast(DatabaseManager::getConnection());

            if ($reverted === []) {
                $this->warn('Nothing to rollback.');
                return 0;
            }

            foreach ($reverted as $name) {
                $this->info('↩ ' . $name);
            }
            $this->line();
            $this->info(count($reverted) . ' migración(es) revertida(s).');

            return 0;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }
}