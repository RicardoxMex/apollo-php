<?php
// core/Console/Commands/DatabaseRefreshCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;

class DatabaseRefreshCommand extends Command
{
    protected string $signature = 'db:refresh';
    protected string $description = 'Drop all tables, run all migrations, and run seeders (migrate:fresh --seed)';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        $setup = $this->app->make(DatabaseSetupCommand::class);
        if ($setup->handle() !== 0) {
            return 1;
        }

        $this->line();

        $seed = $this->app->make(DatabaseSeedCommand::class);
        if ($seed->handle() !== 0) {
            return 1;
        }

        $this->line();
        $this->info('✅ Database refreshed (setup + seed)');

        return 0;
    }
}