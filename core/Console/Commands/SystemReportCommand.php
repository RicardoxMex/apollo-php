<?php
// core/Console/Commands/SystemReportCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;

class SystemReportCommand extends Command
{
    protected string $signature = 'system:report';
    protected string $description = 'Generate a system report';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        $this->info('Apollo Framework System Report');
        $this->line('================================');
        $this->line();

        // Información básica
        $this->line('Framework Version: ' . $this->app->version());
        $this->line('PHP Version: ' . PHP_VERSION);
        $this->line('Environment: ' . $this->app->make('config')->get('env', 'unknown'));
        $this->line('Current Time: ' . date('Y-m-d H:i:s'));
        $this->line();

        // Apps cargadas
        $loadedApps = $this->app->getLoadedApps();
        $this->info('Loaded Apps (' . count($loadedApps) . '):');
        foreach ($loadedApps as $app) {
            $this->line('  - ' . $app);
        }
        $this->line();

        // Rutas registradas
        $router = $this->app->make('router');
        $routes = $router->getRoutes();
        $this->info('Total Routes: ' . count($routes));
        
        // Estadísticas por método
        $methodCounts = [];
        foreach ($routes as $route) {
            $method = $route->method;
            $methodCounts[$method] = ($methodCounts[$method] ?? 0) + 1;
        }
        
        foreach ($methodCounts as $method => $count) {
            $this->line("  {$method}: {$count}");
        }
        $this->line();

        // Información del sistema
        $this->info('System Information:');
        $this->line('OS: ' . PHP_OS);
        $this->line('Memory Limit: ' . ini_get('memory_limit'));
        $this->line('Max Execution Time: ' . ini_get('max_execution_time') . 's');
        $this->line();

        $this->info('Report generated successfully!');
        
        return 0;
    }
}