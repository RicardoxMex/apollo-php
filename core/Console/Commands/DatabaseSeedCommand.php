<?php
// core/Console/Commands/DatabaseSeedCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;

class DatabaseSeedCommand extends Command
{
    protected string $signature = 'db:seed';
    protected string $description = 'Run all seeders from database/seeds';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        try {
            $this->line('🚀 Running Apollo Seeders...');

            $seedFiles = glob($this->app->make('path.database') . '/seeds/*.php');

            if (!$seedFiles) {
                $this->warn('No seeders found in database/seeds.');
                return 0;
            }

            sort($seedFiles);

            foreach ($seedFiles as $file) {
                $className = pathinfo($file, PATHINFO_FILENAME);
                require_once $file;

                if (!class_exists($className)) {
                    $this->warn("  ⚠️  Seeder class '{$className}' not found in " . basename($file));
                    continue;
                }

                $this->line("\nRunning {$className}...");
                $seeder = new $className();
                $seeder->run();
            }

            $this->line("\n✅ All seeders completed successfully!");

            return 0;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }
}