<?php
// apps/Users/Providers/UsersServiceProvider.php

namespace Apps\Users\Providers;

use Apollo\Core\Container\ServiceProvider;
use Apps\Users\Controllers\UserController;
use Apps\Users\Services\UserService;
use Apps\Users\Repositories\UserRepository;
use Apps\Users\Middleware\LoggingMiddleware;
use Apps\Users\Middleware\CorsMiddleware;

class UsersServiceProvider extends ServiceProvider {
    public function register(): void {
        // Registrar repositorio
        $this->container->bind(UserRepository::class, fn($container) => 
            new UserRepository()
        );
        
        // Registrar servicio
        $this->container->bind(UserService::class, fn($container) => 
            new UserService($container->make(UserRepository::class))
        );
        
        // Registrar controller
        $this->container->bind(UserController::class, fn($container) => 
            new UserController($container, $container->make(UserService::class))
        );
        
        // Middlewares propios de esta app (auth/roles se resuelven desde ApolloAuth)
        $this->container->bind('logging', fn($container) => new LoggingMiddleware());
        $this->container->bind('cors', fn($container) => new CorsMiddleware());
    }
    
    public function boot(): void {
        // Las rutas se cargan automáticamente desde Routes/api.php
        // Aquí podríamos registrar middleware específico de la app
        // o configuraciones adicionales
        
        if (php_sapi_name() !== 'cli') {
            error_log("🚀 UsersServiceProvider booted with middlewares");
        }
    }
}