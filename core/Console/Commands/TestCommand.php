<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;

class TestCommand extends Command
{
    protected string $signature = 'test';
    protected string $description = 'Run framework self-check (no database required)';
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        $this->info('Apollo Framework self-check');
        $this->line();

        $failed = false;

        // 1. Configuración cargada
        try {
            $config = $this->app->make('config');
            $name = $config->get('app.name', 'Apollo Framework');
            $this->line("  [OK] Config loaded (app: {$name})");
        } catch (\Throwable $e) {
            $this->error("  [FAIL] Config load: {$e->getMessage()}");
            $failed = true;
        }

        // 2. Apps registradas
        try {
            $apps = $this->app->getLoadedApps();
            $this->line("  [OK] Apps loaded: " . (empty($apps) ? 'none' : implode(', ', $apps)));
            if (empty($apps)) {
                $this->warn('  [WARN] No apps registered — run `php apollo route:list` to check');
            }
        } catch (\Throwable $e) {
            $this->error("  [FAIL] Apps: {$e->getMessage()}");
            $failed = true;
        }

        // 3. Rutas registradas
        try {
            $router = $this->app->make('router');
            $routes = $router->getRoutes();
            $this->line("  [OK] Routes registered: " . count($routes));
        } catch (\Throwable $e) {
            $this->error("  [FAIL] Router: {$e->getMessage()}");
            $failed = true;
        }

        // 4. Entorno
        $this->line("  [OK] Environment: " . (env('APP_ENV', 'production')) .
            (env('APP_DEBUG', false) ? ' (debug ON)' : ''));

        if (env('JWT_SECRET_KEY')) {
            $this->line("  [OK] JWT secret key configured");
        } else {
            $this->warn("  [WARN] JWT_SECRET_KEY not set in .env — auth endpoints will reject tokens");
        }

        $this->line();

        if ($failed) {
            $this->error('Self-check FAILED');
            return 1;
        }

        $this->info('Self-check passed');
        return 0;
    }
}