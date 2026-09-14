<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * E2E de pagos manuales (M4, AC-06) por el kernel real: torneo con fee →
 * inscripción → registrar pago (POST) → listado con inscripción resuelta →
 * payments[] en solicitudes → eliminar → 403 a terceros → 422 → 401.
 * SQLite :memory: con migraciones reales.
 */
class PaymentE2EFlowTest extends TestCase
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

    private function dispatchJson(string $method, string $uri, array $body = [], string $token = ''): array
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'localhost',
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($token !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $response = self::$app->handle(new Request([], [], [], [], [], $server, json_encode($body)));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    public function test_e2e_pagos_manuales(): void
    {
        // ── Usuarios (organizador y tercero) ──
        $this->dispatchJson('POST', '/api/auth/register', [
            'username' => 'org_pay_e2e', 'email' => 'org.pay.e2e@test.local', 'password' => 'clave-pay-1',
        ]);
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'org.pay.e2e@test.local', 'password' => 'clave-pay-1',
        ]);
        $this->assertSame(200, $status);
        $orgToken = $body['data']['token'];

        $this->dispatchJson('POST', '/api/auth/register', [
            'username' => 'ter_pay_e2e', 'email' => 'ter.pay.e2e@test.local', 'password' => 'clave-pay-1',
        ]);
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'ter.pay.e2e@test.local', 'password' => 'clave-pay-1',
        ]);
        $this->assertSame(200, $status);
        $terToken = $body['data']['token'];

        // ── Torneo con fee (AC-06) ──
        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => 'Copa Paga',
            'format' => 'round-robin',
            'max_participants' => 8,
            'visibility' => 'publico',
            'registration_fee' => 500,
            'currency' => 'MXN',
        ], $orgToken);
        $this->assertSame(201, $status);
        $this->assertSame(500.0, (float) $body['data']['registration_fee'], 'Fee persistido');
        $tournamentId = $body['data']['id'];

        // ── Inscripción + pago manual ──
        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Pagada FC'], $orgToken);
        $this->assertSame(201, $status);
        $teamId = $body['data']['id'];

        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations", ['team_id' => $teamId], $orgToken);
        $this->assertSame(201, $status);
        $registrationId = $body['data']['id'];

        // AC-06: registrar pago manual (monto sugerido = fee, método, referencia).
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/payments", [
            'registration_id' => $registrationId,
            'method' => 'transferencia',
            'reference' => 'TRA-2026-001',
            'notes' => 'Abono completo',
        ], $orgToken);
        $this->assertSame(201, $status, 'Pago registrado (AC-06)');
        $this->assertSame(500.0, (float) $body['data']['amount']);
        $this->assertSame('paid', $body['data']['status']);
        $paymentId = $body['data']['id'];

        // ── Listado con inscripción resuelta ──
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/payments", [], $orgToken);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']);
        $this->assertSame('Pagada FC', $body['data'][0]['registration_name']);
        $this->assertSame('TRA-2026-001', $body['data'][0]['reference']);

        // ── payments[] en las solicitudes (la moderación ve el pago) ──
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/registrations", [], $orgToken);
        $this->assertSame(200, $status);
        $sol = array_values(array_filter($body['data'], fn($r) => (int) $r['id'] === $registrationId))[0];
        $this->assertCount(1, $sol['payments'], 'La solicitud muestra su pago');
        $this->assertSame(500.0, (float) $sol['payments'][0]['amount']);

        // ── Eliminar el registro ──
        [$status] = $this->dispatchJson('DELETE', "/api/tournaments/{$tournamentId}/payments/{$paymentId}", [], $orgToken);
        $this->assertSame(200, $status, 'Pago eliminado');
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/payments", [], $orgToken);
        $this->assertCount(0, $body['data']);

        // ── Seguridad: 403 a terceros, 422 body inválido, 401 sin token ──
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/payments", [
            'registration_id' => $registrationId, 'method' => 'efectivo',
        ], $terToken);
        $this->assertSame(403, $status, 'Tercero no registra pagos');

        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/payments", [
            'method' => 'cripto', 'amount' => 1,
        ], $orgToken);
        $this->assertSame(400, $status, 'Método inválido → 400 (validación del servicio)');
        $this->assertStringContainsString('Método', (string) ($body['message'] ?? ''));

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/payments", ['method' => 'efectivo']);
        $this->assertSame(401, $status, 'Sin token → 401');
    }
}