<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * API de pagos manuales (PAY-02): POST/GET/DELETE /tournaments/{id}/payments,
 * 403 a no-organizador, 422 body inválido, 401 sin token, y payments[] dentro
 * del listado de solicitudes. SQLite :memory:, kernel real in-process.
 */
class PaymentApiTest extends TestCase
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

    private function usuario(string $username, string $email): string
    {
        $this->dispatchJson('POST', '/api/auth/register', [
            'username' => $username,
            'email' => $email,
            'password' => 'clave-pay-1',
        ]);
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'clave-pay-1',
        ]);
        $this->assertSame(200, $status);
        return $body['data']['token'];
    }

    public function test_payments_api_flow(): void
    {
        $orgToken = $this->usuario('org_payapi', 'org.payapi@test.local');
        $thirdToken = $this->usuario('tercero_payapi', 'tercero.payapi@test.local');

        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => 'Copa PayAPI',
            'format' => 'round-robin',
            'max_participants' => 8,
            'visibility' => 'publico',
            'registration_fee' => 300,
            'currency' => 'MXN',
        ], $orgToken);
        $this->assertSame(201, $status);
        $this->assertSame(300.0, (float) $body['data']['registration_fee']);
        $tournamentId = $body['data']['id'];

        // Inscripción del organizador (draft) para ligar el pago.
        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Pay FC'], $orgToken);
        $this->assertSame(201, $status);
        $teamId = $body['data']['id'];
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations", ['team_id' => $teamId], $orgToken);
        $this->assertSame(201, $status);
        $registrationId = $body['data']['id'];

        // Registrar pago (monto por defecto = fee).
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/payments", [
            'registration_id' => $registrationId,
            'method' => 'efectivo',
            'reference' => 'FOLIO-77',
        ], $orgToken);
        $this->assertSame(201, $status, 'Pago registrado');
        $this->assertSame(300.0, (float) $body['data']['amount']);
        $paymentId = $body['data']['id'];

        // Listar pagos con inscripción resuelta.
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/payments", [], $orgToken);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']);
        $this->assertSame('Pay FC', $body['data'][0]['registration_name']);

        // payments[] dentro de las solicitudes.
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/registrations", [], $orgToken);
        $this->assertSame(200, $status);
        $sol = array_values(array_filter($body['data'], fn($r) => (int) $r['id'] === $registrationId))[0];
        $this->assertCount(1, $sol['payments']);
        $this->assertSame('FOLIO-77', $sol['payments'][0]['reference']);

        // 403 a terceros; 422 body inválido; 401 sin token.
        [$status] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/payments", [], $thirdToken);
        $this->assertSame(403, $status);
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/payments", ['method' => 'efectivo', 'amount' => -5], $orgToken);
        $this->assertSame(422, $status);
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/payments", ['method' => 'efectivo']);
        $this->assertSame(401, $status);

        // Eliminar.
        [$status] = $this->dispatchJson('DELETE', "/api/tournaments/{$tournamentId}/payments/{$paymentId}", [], $orgToken);
        $this->assertSame(200, $status);
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/payments", [], $orgToken);
        $this->assertCount(0, $body['data']);
    }
}