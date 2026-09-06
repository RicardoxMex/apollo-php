<?php

namespace Apps\ApolloAuth;

use Apollo\Core\Container\ServiceProvider;
use Apps\ApolloAuth\Services\AuthService;
use Apps\ApolloAuth\Middleware\AuthMiddleware;

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

        // NOTA: los gates de roles/permisos ('role.admin', 'role.user') viven en el
        // módulo de acceso del core (core/Providers/AppServiceProvider), activable
        // vía config('auth.access.enabled').
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