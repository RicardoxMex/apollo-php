<?php
// core/Providers/AppServiceProvider.php

namespace Apollo\Core\Providers;

use Apollo\Core\Container\ServiceProvider;
use Apollo\Core\Http\Kernel;
use Apollo\Core\Router\Router;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {
        // Middleware global
        $this->container->singleton(Kernel::class, function($app) {
            $kernel = new Kernel($app, $app->make('router'));
            $kernel->setMiddleware([
                // Middleware global se agregará aquí
            ]);
            return $kernel;
        });

        // Módulo de roles y permisos (core) — activable vía config('auth.access.enabled')
        if (config('auth.access.enabled', true)) {
            $this->container->bind('role.admin', fn($app) => new \Apollo\Core\Auth\Middleware\RoleMiddleware(['admin']));
            $this->container->bind('role.user', fn($app) => new \Apollo\Core\Auth\Middleware\RoleMiddleware(['user', 'admin']));
        }
        
        // El router ya está registrado en Application.php
        // No necesitamos registrarlo aquí
    }
    
    public function boot(): void {
        // Cargar funciones de base de datos
        require_once __DIR__ . '/../Database/database.php';
        
        // Inicializar base de datos
        \Apollo\Core\Database\initDatabase();
    }
}