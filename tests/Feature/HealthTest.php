<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Tests\TestCase;

/**
 * GET /api/health (público, sin auth): monitoreo/uptime.
 *
 * - 200 { status: "ok", db: true, time } cuando la app arranca y la BD
 *   responde SELECT 1 (harness SQLite :memory:; no hacen falta migraciones).
 * - 503 { status: "degraded", db: false, time } si la BD no responde.
 *
 * Kernel real in-process (mismo patrón que EmailE2EFlowTest).
 */
class HealthTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // El boot (initDatabase) lee estas vars: SQLite en memoria para el caso OK.
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
                // Apps ausentes: mismo comportamiento tolerante que el binario.
            }
        }

        self::$app->bootServiceProviders();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteMemory();
    }

    protected function tearDown(): void
    {
        $this->useSqliteMemory();

        parent::tearDown();
    }

    private function useSqliteMemory(): void
    {
        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        DatabaseManager::disconnect();
    }

    public function test_health_ok_when_database_responds(): void
    {
        [$status, $body] = $this->dispatch('GET', '/api/health');

        $this->assertSame(200, $status);
        $this->assertSame('ok', $body['status']);
        $this->assertTrue($body['db']);
        $this->assertIsString($body['time']);
        $this->assertNotFalse(strtotime($body['time']), 'time debe ser una fecha ISO-8601 válida');
    }

    public function test_health_degraded_when_database_is_down(): void
    {
        // Ruta inválida: el padre es un archivo normal → SQLite no puede abrirla.
        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => dirname(__DIR__, 2) . '/composer.json/invalid-health.sqlite',
        ]);
        DatabaseManager::disconnect();

        [$status, $body] = $this->dispatch('GET', '/api/health');

        $this->assertSame(503, $status);
        $this->assertSame('degraded', $body['status']);
        $this->assertFalse($body['db']);
    }

    public function test_health_is_public_and_returns_json_shape(): void
    {
        [$status, $body] = $this->dispatch('GET', '/api/health');

        $this->assertSame(200, $status);
        $this->assertSame(['status', 'db', 'time'], array_keys($body));
    }
}
