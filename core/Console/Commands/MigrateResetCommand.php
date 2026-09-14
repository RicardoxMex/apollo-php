<?php
// core/Console/Commands/MigrateResetCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;
use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Database\Migrator;

/**
 * Deshace TODOS los batches de migraciones en orden inverso.
 */
class MigrateResetCommand extends Command
{
    protected string $signature = 'migrate:reset';
    protected string $description = 'Rollback all migration batches';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        try {
            $migrator = new Migrator($this->app->make('path.database'));
            $reverted = $migrator->rollbackAll(DatabaseManager::getConnection());

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