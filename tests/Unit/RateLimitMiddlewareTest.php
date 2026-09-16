<?php

namespace Tests\Unit;

use Apollo\Core\Application;
use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apollo\Core\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * RateLimitMiddleware sobre SQLite :memory: (migración 006 rate_limits).
 * Verifica el paso bajo el límite, el 429 con Retry-After al superarlo y que
 * la clave por IP aísla a distintos clientes.
 */
class RateLimitMiddlewareTest extends TestCase
{
    /** Valor previo de la env para no contaminar a otras suites del proceso. */
    private ?string $rateLimitEnvOriginal = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite no disponible');
        }

        $this->rateLimitEnvOriginal = $_ENV['RATE_LIMIT_MAX_ATTEMPTS'] ?? null;
        $_ENV['RATE_LIMIT_MAX_ATTEMPTS'] = '3';
        $_ENV['RATE_LIMIT_WINDOW'] = '900';

        new Application(dirname(__DIR__, 2));
        app('config');

        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        DatabaseManager::disconnect();

        $migration = require dirname(__DIR__, 2) . '/database/migrations/006_create_rate_limits_table.php';
        $migration->up();
    }

    private function request(string $ip = '10.1.1.50'): Request
    {
        return new Request([], [], [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/auth/login',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => $ip,
        ], '');
    }

    public function test_under_limit_passes(): void
    {
        $middleware = new RateLimitMiddleware('login');

        $response = $middleware->handle($this->request(), fn($req) => Response::json(['ok' => true]));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_over_limit_returns_429_with_retry_after(): void
    {
        $middleware = new RateLimitMiddleware('login');
        $next = fn($req) => Response::json(['ok' => true]);

        // 3 intentos permitidos (config RATE_LIMIT_MAX_ATTEMPTS=3)
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $middleware->handle($this->request(), $next)->getStatusCode());
        }

        // El 4º excede el límite → 429
        $response = $middleware->handle($this->request(), $next);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertArrayHasKey('Retry-After', $response->getHeaders());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Too Many Requests', $body['error']);
    }

    public function test_different_ips_do_not_share_the_limit(): void
    {
        $middleware = new RateLimitMiddleware('login');
        $next = fn($req) => Response::json(['ok' => true]);

        // 6 IPs distintas, cada una solo hace 1 intento → todas pasan
        for ($i = 0; $i < 6; $i++) {
            $response = $middleware->handle($this->request("10.0.0.{$i}"), $next);
            $this->assertSame(200, $response->getStatusCode());
        }
    }

    public function test_expired_window_resets_the_counter(): void
    {
        $middleware = new RateLimitMiddleware('login');
        $next = fn($req) => Response::json(['ok' => true]);

        for ($i = 0; $i < 3; $i++) {
            $middleware->handle($this->request(), $next);
        }
        $this->assertSame(429, $middleware->handle($this->request(), $next)->getStatusCode());

        // Expira la ventana (window_start antigua) → el contador se resetea
        DatabaseManager::getConnection()->prepare(
            "UPDATE rate_limits SET window_start = datetime('now', '-1 day')"
        )->execute();

        $this->assertSame(200, $middleware->handle($this->request(), $next)->getStatusCode());
    }

    protected function tearDown(): void
    {
        if ($this->rateLimitEnvOriginal === null) {
            unset($_ENV['RATE_LIMIT_MAX_ATTEMPTS']);
        } else {
            $_ENV['RATE_LIMIT_MAX_ATTEMPTS'] = $this->rateLimitEnvOriginal;
        }

        parent::tearDown();
    }
}