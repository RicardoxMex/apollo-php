<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * Edición de perfil (PROFILE-01, REQ-12): PUT /auth/profile actualiza
 * first_name/last_name/phone/avatar, no permite cambiar el email, valida 422.
 * SQLite :memory: con migraciones reales, kernel real in-process.
 */
class AuthProfileUpdateTest extends TestCase
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

    private function dispatchJson(string $method, string $uri, array $body, string $token = ''): array
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

    private function registrarYloguear(string $username, string $email): string
    {
        [$status] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => $username,
            'email' => $email,
            'password' => 'clave-perfil-1',
        ]);
        $this->assertSame(201, $status);

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'clave-perfil-1',
        ]);
        $this->assertSame(200, $status);
        return $body['data']['token'];
    }

    public function test_update_profile_persists_fields(): void
    {
        $token = $this->registrarYloguear('perfil1', 'perfil1@test.local');

        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'first_name' => 'Ana',
            'last_name' => 'García',
            'phone' => '+52 55 1234 5678',
            'avatar' => 'https://cdn.test/avatars/ana.png',
        ], $token);
        $this->assertSame(200, $status, 'PUT /auth/profile 200');
        $this->assertSame('Ana', $body['data']['user']['first_name']);
        $this->assertSame('García', $body['data']['user']['last_name']);
        $this->assertSame('Ana García', $body['data']['user']['full_name']);

        // Persistido: GET /auth/profile lo devuelve.
        [$status, $body] = $this->dispatchJson('GET', '/api/auth/profile', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame('Ana', $body['data']['user']['first_name']);
        $this->assertSame('+52 55 1234 5678', $body['data']['user']['phone']);
        $this->assertSame('https://cdn.test/avatars/ana.png', $body['data']['user']['avatar']);
    }

    public function test_update_profile_ignores_email_and_validates(): void
    {
        $token = $this->registrarYloguear('perfil2', 'perfil2@test.local');

        // El email no es editable: se ignora y se mantiene el original.
        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'first_name' => 'Pepe',
            'email' => 'otro@test.local',
        ], $token);
        $this->assertSame(200, $status);
        $this->assertSame('perfil2@test.local', $body['data']['user']['email']);

        // Campos inválidos → 422.
        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'first_name' => str_repeat('x', 200),
        ], $token);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('errors', $body);

        // Sin token → 401.
        [$status] = $this->dispatchJson('PUT', '/api/auth/profile', ['first_name' => 'X']);
        $this->assertSame(401, $status);
    }

    public function test_update_profile_clears_with_empty_string(): void
    {
        $token = $this->registrarYloguear('perfil3', 'perfil3@test.local');

        [$status] = $this->dispatchJson('PUT', '/api/auth/profile', ['first_name' => 'Luis'], $token);
        $this->assertSame(200, $status);

        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', ['first_name' => ''], $token);
        $this->assertSame(200, $status);
        $this->assertNull($body['data']['user']['first_name']);
    }
}