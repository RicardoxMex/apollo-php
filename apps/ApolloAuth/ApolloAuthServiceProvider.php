<?php

namespace Apps\ApolloAuth;

use Apollo\Core\Container\ServiceProvider;
use Apollo\Core\Middleware\RateLimitMiddleware;
use Apps\ApolloAuth\Services\AuthService;
use Apps\ApolloAuth\Services\PasswordResetService;
use Apps\ApolloAuth\Services\VerificationService;
use Apps\ApolloAuth\Middleware\AuthMiddleware;

class ApolloAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AuthService singleton (la fachada Auth resuelve por clase)
        $this->container->singleton(AuthService::class, function ($app) {
            return new AuthService();
        });

        // VerificationService singleton (verificación de email, D2)
        $this->container->singleton(VerificationService::class, function ($app) {
            return new VerificationService();
        });

        // PasswordResetService singleton (recuperación de contraseña, D3)
        $this->container->singleton(PasswordResetService::class, function ($app) {
            return new PasswordResetService();
        });

        // AuthMiddleware singleton (usa el AuthService)
        $this->container->singleton(AuthMiddleware::class, function ($app) {
            return new AuthMiddleware($app->make(AuthService::class));
        });

        // Alias de middleware: 'auth' = JWT real de ApolloAuth
        $this->container->bind('auth', AuthMiddleware::class);

        // Rate limiting en credenciales (por IP): evita fuerza bruta en login/register
        $this->container->bind('rate_limit.login', fn() => new RateLimitMiddleware('login'));

        // Rate limiting de flujos de email (verificación/reset): bucket aparte
        // para no bloquear a un usuario legítimo que hace register→verify→
        // forgot→reset→login en la misma ventana (descubierto en E2E de M1).
        $this->container->bind('rate_limit.email', fn() => new RateLimitMiddleware('email'));

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