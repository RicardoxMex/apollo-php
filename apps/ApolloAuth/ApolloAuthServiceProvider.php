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
        // Register AuthService as singleton
        $this->container->singleton(AuthService::class, function ($app) {
            return new AuthService();
        });

        // Register Auth facade (usar otro alias para evitar conflicto con middleware)
        $this->container->singleton('auth.service', function ($app) {
            return $app->make(AuthService::class);
        });

        // Register middleware
        $this->container->singleton(AuthMiddleware::class, function ($app) {
            return new AuthMiddleware($app->make(AuthService::class));
        });

        $this->container->singleton(RoleMiddleware::class, function ($app) {
            return new RoleMiddleware();
        });

        $this->container->singleton(PermissionMiddleware::class, function ($app) {
            return new PermissionMiddleware();
        });
    }

    public function boot(): void
    {
        // Register middleware aliases
        $router = $this->container->make('router');
        
        // Register middleware aliases directly in container
        $this->container->singleton('auth', function ($app) {
            return $app->make(AuthMiddleware::class);
        });
        
        $this->container->singleton('role', function ($app) {
            return $app->make(RoleMiddleware::class);
        });
        
        $this->container->singleton('permission', function ($app) {
            return $app->make(PermissionMiddleware::class);
        });

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