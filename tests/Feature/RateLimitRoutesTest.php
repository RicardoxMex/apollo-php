<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * Rate limiting aplicado a las rutas de autenticación (RATE-01, D-F0-3):
 * login/register comparten el bucket 'login' y los flujos de email
 * (verify/forgot/reset/resend) comparten el bucket 'email'. Al superar
 * max_attempts la ruta responde 429. SQLite :memory: con migraciones reales.
 */
class RateLimitRoutesTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';

        $config = self::$app->make('config');
        foreach ($config->get('providers.core', []) as $providerClass) {
            if (class_exists($providerClass)) {
                self::$app->registerServiceProvider(new $providerClass(self::$app));
            }
        }
        foreach ($config->get('providers.app', []) as $providerClass) {
            if (class_exists($providerClass)) {
                self::$app->registerServiceProvider(new $providerClass(self::$app));
            }
        }
        foreach ($config->get('apps.registered', []) as $appName) {
            try {
                self::$app->registerApp($appName);
            } catch (\Throwable $e) {
            }
        }
        self::$app->bootServiceProviders();
        DatabaseManager::disconnect();
        self::$pdo = DatabaseManager::getConnection();

        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }
    }

    private function dispatchJson(string $method, string $uri, array $body = []): array
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'localhost',
            'CONTENT_TYPE' => 'application/json',
        ];
        $response = self::$app->handle(new Request([], [], [], [], [], $server, json_encode($body)));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    /** Límite efectivo configurado (env/phpunit pueden ajustarlo). */
    private function maxAttempts(): int
    {
        $config = self::$app->make('config')->get('auth.rate_limit', []);
        return max(1, (int) ($config['max_attempts'] ?? 5));
    }

    private function purgarRateLimits(): void
    {
        self::$pdo->prepare('DELETE FROM rate_limits')->execute();
    }

    public function test_login_y_register_comparten_bucket_y_devuelven_429(): void
    {
        $this->purgarRateLimits();
        $max = $this->maxAttempts();

        // Dentro del límite el login falla de verdad (401), no por limiter.
        for ($i = 0; $i < $max; $i++) {
            [$status] = $this->dispatchJson('POST', '/api/auth/login', [
                'email' => 'nadie@test.local',
                'password' => 'clave-incorrecta',
            ]);
            $this->assertSame(401, $status, "Intento {$i} dentro del límite");
        }

        // El intento max+1 → 429 (AC-03).
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'nadie@test.local',
            'password' => 'clave-incorrecta',
        ]);
        $this->assertSame(429, $status, 'Login sobre el límite → 429 (AC-03)');
        $this->assertSame('Too Many Requests', $body['error'] ?? null);

        // Register comparte el bucket 'login' (misma IP).
        [$status] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => 'rate_user',
            'email' => 'rate.user@test.local',
            'password' => 'clave-rate-1',
        ]);
        $this->assertSame(429, $status, 'Register comparte el bucket → 429');
    }

    public function test_flujos_de_email_comparten_bucket_y_devuelven_429(): void
    {
        $this->purgarRateLimits();
        $max = $this->maxAttempts();

        for ($i = 0; $i < $max; $i++) {
            [$status] = $this->dispatchJson('POST', '/api/auth/forgot-password', [
                'email' => 'nadie@test.local',
            ]);
            $this->assertSame(200, $status, "Forgot {$i} dentro del límite");
        }

        [$status] = $this->dispatchJson('POST', '/api/auth/forgot-password', [
            'email' => 'nadie@test.local',
        ]);
        $this->assertSame(429, $status, 'Forgot sobre el límite → 429');

        // verify-email comparte el bucket 'email'.
        [$status] = $this->dispatchJson('POST', '/api/auth/verify-email', ['token' => 'token-x']);
        $this->assertSame(429, $status, 'Verify comparte el bucket → 429');
    }
}
