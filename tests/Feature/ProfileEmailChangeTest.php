<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * Cambio de email desde el perfil: PUT /auth/profile exige la contraseña
 * actual, rechaza emails en uso, re-verifica el correo nuevo (token nuevo,
 * email_verified_at NULL) y avisa al correo anterior (best-effort).
 * SQLite :memory: con migraciones reales, kernel real in-process.
 */
class ProfileEmailChangeTest extends TestCase
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
        // Purga el bucket de rate limit: cada método del test registra y
        // loguea un usuario en la misma ventana (mismo patrón que
        // EmailE2EFlowTest; el 429 real lo cubre RateLimitRoutesTest).
        self::$pdo->prepare('DELETE FROM rate_limits')->execute();

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

    private function idDeUsuario(string $email): int
    {
        $stmt = self::$pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> hashes de tokens de verificación sin usar */
    private function tokensSinUsar(int $userId): array
    {
        $stmt = self::$pdo->prepare('SELECT token FROM email_verifications WHERE user_id = ? AND used = 0');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function marcarVerificado(string $email): void
    {
        $stmt = self::$pdo->prepare('UPDATE users SET email_verified_at = ? WHERE email = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $email]);
    }

    public function test_cambio_de_email_sin_password_actual_falla_422(): void
    {
        $token = $this->registrarYloguear('cambia1', 'cambia1@test.local');

        // Sin current_password → 422 y el email no cambia.
        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'email' => 'nuevo1@test.local',
        ], $token);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('current_password', $body['errors']);
        $this->assertSame('cambia1@test.local', $this->obtenerEmail('cambia1@test.local'));

        // Contraseña incorrecta → 422 y el email no cambia.
        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'email' => 'nuevo1@test.local',
            'current_password' => 'contraseña-incorrecta',
        ], $token);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('current_password', $body['errors']);
        $this->assertSame('cambia1@test.local', $this->obtenerEmail('cambia1@test.local'));

        // El perfil sigue mostrando el email original.
        [$status, $body] = $this->dispatchJson('GET', '/api/auth/profile', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame('cambia1@test.local', $body['data']['user']['email']);
    }

    public function test_cambio_de_email_en_uso_por_otro_usuario_falla_422(): void
    {
        $token = $this->registrarYloguear('cambia2', 'cambia2@test.local');

        $stmt = self::$pdo->prepare(
            "INSERT INTO users (username, email, password, status) VALUES (?, ?, ?, 'active')"
        );
        $stmt->execute(['duplicado', 'duplicado@test.local', password_hash('x', PASSWORD_DEFAULT)]);

        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'email' => 'duplicado@test.local',
            'current_password' => 'clave-perfil-1',
        ], $token);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('email', $body['errors']);
        $this->assertSame('cambia2@test.local', $this->obtenerEmail('cambia2@test.local'));

        // La comparación es case-insensitive: MAYÚSCULAS del email de otro también choca.
        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'email' => 'DUPLICADO@test.local',
            'current_password' => 'clave-perfil-1',
        ], $token);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('email', $body['errors']);
    }

    public function test_cambio_de_email_valido_reverifica_y_emite_token_nuevo(): void
    {
        $token = $this->registrarYloguear('cambia3', 'cambia3@test.local');
        $userId = $this->idDeUsuario('cambia3@test.local');

        // El registro ya emitió un token; el cambio debe revocarlo y emitir otro.
        $tokensPrevios = $this->tokensSinUsar($userId);
        $this->assertNotEmpty($tokensPrevios);

        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'first_name' => 'Ana',
            'email' => 'nuevo3@test.local',
            'current_password' => 'clave-perfil-1',
        ], $token);
        $this->assertSame(200, $status);
        $this->assertSame('nuevo3@test.local', $body['data']['user']['email']);
        $this->assertSame('Ana', $body['data']['user']['first_name']);
        $this->assertFalse($body['data']['user']['email_verified']);
        $this->assertNull($body['data']['user']['email_verified_at']);

        // Persistido: email nuevo y email_verified_at NULL en DB.
        $stmt = self::$pdo->prepare('SELECT email, email_verified_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('nuevo3@test.local', $row['email']);
        $this->assertNull($row['email_verified_at']);

        // Token nuevo sin usar, distinto del previo; los anteriores quedan revocados.
        $tokensNuevos = $this->tokensSinUsar($userId);
        $this->assertCount(1, $tokensNuevos);
        $this->assertNotContains($tokensNuevos[0], $tokensPrevios);

        $stmt = self::$pdo->prepare(
            'SELECT used FROM email_verifications WHERE token = ?'
        );
        $stmt->execute([$tokensPrevios[0]]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // El token vigente pertenece al usuario, cuyo email ya es el nuevo.
        $stmt = self::$pdo->prepare(
            'SELECT u.email FROM email_verifications ev
             JOIN users u ON u.id = ev.user_id
             WHERE ev.token = ? AND ev.used = 0'
        );
        $stmt->execute([$tokensNuevos[0]]);
        $this->assertSame('nuevo3@test.local', $stmt->fetchColumn());
    }

    public function test_mismo_email_sin_password_no_reverifica(): void
    {
        $token = $this->registrarYloguear('cambia4', 'cambia4@test.local');
        $userId = $this->idDeUsuario('cambia4@test.local');

        $this->marcarVerificado('cambia4@test.local');
        $tokensPrevios = $this->tokensSinUsar($userId);

        // Mismo email (aunque cambie el caso) sin contraseña → 200 y sigue verificado.
        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'email' => 'CAMBIa4@test.local',
            'phone' => '+52 55 0000 0000',
        ], $token);
        $this->assertSame(200, $status);
        $this->assertTrue($body['data']['user']['email_verified']);
        $this->assertSame('+52 55 0000 0000', $body['data']['user']['phone']);
        $this->assertSame('cambia4@test.local', $body['data']['user']['email']);

        // Estado de verificación intacto: sigue verificado y sin token nuevo.
        $stmt = self::$pdo->prepare('SELECT email_verified_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $this->assertNotNull($stmt->fetchColumn());
        $this->assertSame($tokensPrevios, $this->tokensSinUsar($userId));
    }

    public function test_cambio_de_email_sin_autenticacion_401(): void
    {
        [$status] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'email' => 'nadie@test.local',
            'current_password' => 'clave-perfil-1',
        ]);
        $this->assertSame(401, $status);
    }

    private function obtenerEmail(string $email): string
    {
        $stmt = self::$pdo->prepare('SELECT email FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return (string) $stmt->fetchColumn();
    }
}
