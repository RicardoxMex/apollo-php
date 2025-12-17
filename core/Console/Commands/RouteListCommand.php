<?php
// core/Console/Commands/RouteListCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Router\Router;

class RouteListCommand extends Command
{
    protected string $signature = 'route:list';
    protected string $description = 'List all registered routes';

    private Router $router;

    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    public function handle(): int
    {
        $routes = $this->router->getRoutes();

        if (empty($routes)) {
            $this->warn('No routes registered.');
            return 0;
        }

        $this->line();
        $counter = 1;

        foreach ($routes as $route) {
            $method = str_pad($route->method, 6);
            $uri = str_pad($route->uri, 30);
            $name = str_pad($route->name ?: 'none', 20);
            $middleware = $this->getMiddlewareList($route->middleware);
            
            echo "{$counter}. {$method} {$uri} {$name} middleware: {$middleware}\n";
            $counter++;
        }

        $this->line();
        $this->info('Total routes: ' . count($routes));

        return 0;
    }

    private function getActionName($action): string
    {
        if (is_string($action)) {
            return $action;
        }

        if (is_array($action) && isset($action['uses'])) {
            return $action['uses'];
        }

        if ($action instanceof \Closure) {
            return 'Closure';
        }

        return 'Unknown';
    }

    private function getMiddlewareList(array $middleware): string
    {
        if (empty($middleware)) {
            return 'none';
        }

        // Convertir middleware a nombres legibles
        $names = array_map(function ($mw) {
            if (is_string($mw)) {
                // Extraer nombre de clase sin namespace
                $parts = explode('\\', $mw);
                $className = end($parts);
                
                // Convertir CamelCase a snake_case y remover "Middleware"
                $name = strtolower(preg_replace('/([A-Z])/', '_$1', $className));
                $name = ltrim($name, '_');
                $name = str_replace('_middleware', '', $name);
                
                return $name;
            }
            
            return (string) $mw;
        }, $middleware);

        return implode(', ', $names);
    }
}