<?php
// core/Console/Commands/MigrateCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;
use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Database\Migrator;

/**
 * Aplica SOLO las migraciones pendientes (no destructivo): a diferencia de
 * db:setup, no toca las tablas ya migradas.
 */
class MigrateCommand extends Command
{
    protected string $signature = 'migrate';
    protected string $description = 'Run pending migrations (non-destructive)';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        try {
            $migrator = new Migrator($this->app->make('path.database'));
            $run = $migrator->migrate(DatabaseManager::getConnection());

            if ($run === []) {
                $this->warn('Nothing to migrate.');
                return 0;
            }

            foreach ($run as $name) {
                $this->info('✓ ' . $name);
            }
            $this->line();
            $this->info(count($run) . ' migración(es) aplicada(s).');

            return 0;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }
}