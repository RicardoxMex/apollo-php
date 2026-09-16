<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * Perímetro de API (R-PERIM-01): /api/users solo admin y con proyección
 * segura (sin password), /api/audit-logs solo admin, y endpoints admin del
 * backoffice (ApolloAuth) responden 501 "Pendiente Fase 2" en lugar de 400/500.
 * SQLite :memory: con migraciones reales y RolesSeeder.
 */
class ApiPerimeterTest extends TestCase
{
    private static PDO $pdo;
    private static ?string $adminToken = null;
    private static ?string $userToken = null;

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

        // Admin real del seeder: admin@apollo.local / admin123
        require_once dirname(__DIR__, 2) . '/database/seeds/RolesSeeder.php';
        ob_start();
        (new \RolesSeeder())->run();
        ob_end_clean();
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

    private function login(string $email, string $password): string
    {
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(200, $status, "Login {$email}");
        return $body['data']['token'];
    }

    private function adminToken(): string
    {
        if (self::$adminToken === null) {
            self::$adminToken = $this->login('admin@apollo.local', 'admin123');
        }
        return self::$adminToken;
    }

    private function userToken(): string
    {
        if (self::$userToken === null) {
            [$status] = $this->dispatchJson('POST', '/api/auth/register', [
                'username' => 'perim_user',
                'email' => 'perim.user@test.local',
                'password' => 'clave-perim-1',
            ]);
            $this->assertSame(201, $status);
            self::$userToken = $this->login('perim.user@test.local', 'clave-perim-1');
        }
        return self::$userToken;
    }

    public function test_users_index_requires_admin_and_hides_password(): void
    {
        // Anónimo → 401
        [$status] = $this->dispatchJson('GET', '/api/users');
        $this->assertSame(401, $status, 'Anónimo no lista usuarios');

        // Usuario autenticado sin rol admin → 403
        [$status] = $this->dispatchJson('GET', '/api/users', [], $this->userToken());
        $this->assertSame(403, $status, 'No-admin no lista usuarios');

        // Admin → 200 y respuesta sin password
        [$status, $body] = $this->dispatchJson('GET', '/api/users', [], $this->adminToken());
        $this->assertSame(200, $status, 'Admin lista usuarios');
        $this->assertTrue($body['success']);
        $this->assertStringNotContainsString('"password"', json_encode($body));
    }

    public function test_users_show_projects_safe_fields_only(): void
    {
        // Anónimo → 401
        [$status] = $this->dispatchJson('GET', '/api/users/1');
        $this->assertSame(401, $status);

        // No-admin → 403
        [$status] = $this->dispatchJson('GET', '/api/users/1', [], $this->userToken());
        $this->assertSame(403, $status);

        // Admin → 200 con solo los campos seguros
        [$status, $body] = $this->dispatchJson('GET', '/api/users/1', [], $this->adminToken());
        $this->assertSame(200, $status);
        $this->assertSame([
            'id',
            'username',
            'email',
            'first_name',
            'last_name',
            'status',
            'email_verified_at',
            'created_at',
            'updated_at',
        ], array_keys($body['data']));
        $this->assertStringNotContainsString('"password"', json_encode($body));
    }

    public function test_audit_logs_requires_admin(): void
    {
        [$status] = $this->dispatchJson('GET', '/api/audit-logs');
        $this->assertSame(401, $status, 'Anónimo → 401');

        [$status] = $this->dispatchJson('GET', '/api/audit-logs', [], $this->userToken());
        $this->assertSame(403, $status, 'No-admin → 403');

        [$status, $body] = $this->dispatchJson('GET', '/api/audit-logs', [], $this->adminToken());
        $this->assertSame(200, $status, 'Admin → 200');
        $this->assertTrue($body['success']);
    }

    public function test_broken_admin_endpoints_return_501(): void
    {
        $token = $this->adminToken();

        $cases = [
            ['GET', '/api/auth/admin/users'],
            ['GET', '/api/auth/admin/users/1'],
            ['PUT', '/api/auth/admin/users/1'],
            ['POST', '/api/auth/admin/users/1/roles'],
            ['DELETE', '/api/auth/admin/users/1/roles/admin'],
            ['PUT', '/api/auth/admin/roles/admin'],
            ['DELETE', '/api/auth/admin/roles/admin'],
        ];

        foreach ($cases as [$method, $uri]) {
            [$status, $body] = $this->dispatchJson($method, $uri, [], $token);
            $this->assertSame(501, $status, "{$method} {$uri} debe responder 501");
            $this->assertSame('Not implemented', $body['error'] ?? null, "{$method} {$uri} error");
            $this->assertSame('Pendiente Fase 2', $body['message'] ?? null, "{$method} {$uri} message");
        }
    }

    public function test_broken_admin_endpoints_still_require_auth(): void
    {
        $cases = [
            ['GET', '/api/auth/admin/users'],
            ['PUT', '/api/auth/admin/roles/admin'],
        ];

        foreach ($cases as [$method, $uri]) {
            [$status, $body] = $this->dispatchJson($method, $uri);
            $this->assertSame(401, $status, "{$method} {$uri} sin token");
            $this->assertSame('Unauthorized', $body['error'] ?? null);
        }
    }
}
