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
        
        // El router ya está registrado en Application.php
        // No necesitamos registrarlo aquí
    }
    
    public function boot(): void {
        // Código de inicialización
    }
}