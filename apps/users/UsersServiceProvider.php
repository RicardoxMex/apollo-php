<?php
// apps/users/UsersServiceProvider.php

namespace Apps\Users;

use Apollo\Core\Container\ServiceProvider;
use Apps\Users\Controllers\UserController;
use Apps\Users\Services\UserService;
use Apps\Users\Repositories\UserRepository;
use Apps\Users\Middleware\Authenticate;
use Apps\Users\Middleware\RoleMiddleware;
use Apps\Users\Middleware\LoggingMiddleware;
use Apps\Users\Middleware\CorsMiddleware;

class UsersServiceProvider extends ServiceProvider {
    public function register(): void {
        // Registrar repositorio
        $this->container->bind(UserRepository::class, fn($container) => 
            new UserRepository($container)
        );
        
        // Registrar servicio
        $this->container->bind(UserService::class, fn($container) => 
            new UserService($container->make(UserRepository::class))
        );
        
        // Registrar controller
        $this->container->bind(UserController::class, fn($container) => 
            new UserController($container->make(UserService::class))
        );
        
        // Registrar middlewares
        $this->container->bind('auth', fn($container) => new Authenticate());
        $this->container->bind('role', fn($container) => new RoleMiddleware());
        $this->container->bind('role.admin', fn($container) => new RoleMiddleware(['admin']));
        $this->container->bind('role.user', fn($container) => new RoleMiddleware(['user', 'admin']));
        $this->container->bind('logging', fn($container) => new LoggingMiddleware());
        $this->container->bind('cors', fn($container) => new CorsMiddleware());
    }
    
    public function boot(): void {
        // Las rutas se cargan automáticamente desde Routes/api.php
        // Aquí podríamos registrar middleware específico de la app
        // o configuraciones adicionales
        
        error_log("🚀 UsersServiceProvider booted with middlewares");
    }
}