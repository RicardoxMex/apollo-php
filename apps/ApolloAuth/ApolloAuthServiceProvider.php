<?php

namespace Apps\ApolloAuth;

use Apollo\Core\Container\ServiceProvider;
use Apps\ApolloAuth\Services\AuthService;
use Apps\ApolloAuth\Middleware\AuthMiddleware;
use Apps\ApolloAuth\Middleware\RoleMiddleware;
use Apps\ApolloAuth\Middleware\PermissionMiddleware;

class ApolloAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AuthService singleton (la fachada Auth resuelve por clase)
        $this->container->singleton(AuthService::class, function ($app) {
            return new AuthService();
        });

        // AuthMiddleware singleton (usa el AuthService)
        $this->container->singleton(AuthMiddleware::class, function ($app) {
            return new AuthMiddleware($app->make(AuthService::class));
        });

        // Alias de middleware: 'auth' = JWT real de ApolloAuth
        $this->container->bind('auth', AuthMiddleware::class);

        // Alias de roles (según convención de las rutas); 'role' se omite a propósito
        // para que un uso sin rol explícito falle en voz alta en lugar de pasar en silencio
        $this->container->bind('role.admin', fn($app) => new RoleMiddleware(['admin']));
        $this->container->bind('role.user', fn($app) => new RoleMiddleware(['user', 'admin']));

        $this->container->singleton(RoleMiddleware::class, function ($app) {
            return new RoleMiddleware();
        });

        $this->container->singleton(PermissionMiddleware::class, function ($app) {
            return new PermissionMiddleware();
        });
    }

    public function boot(): void
    {
        // Load helpers
        $this->loadHelpers();
    }

    /**
     * Load helper functions
     */
    private function loadHelpers(): void
    {
        $helpersFile = __DIR__ . '/helpers.php';
        if (file_exists($helpersFile)) {
            require_once $helpersFile;
        }
    }
}