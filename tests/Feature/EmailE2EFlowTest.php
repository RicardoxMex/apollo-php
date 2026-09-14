<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * E2E completo de M1 (EMAIL-06) a través del kernel real (in-process, sin
 * servidor): registro → email de verificación → publish bloqueado (403
 * EMAIL_NOT_VERIFIED) → verify → publish OK → forgot-password → reset →
 * login con la nueva contraseña → inscripción con emails transaccionales.
 * SQLite :memory: con migraciones reales.
 *
 * AC-01/AC-02/AC-03 de la spec.
 */
class EmailE2EFlowTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // SQLite en memoria: initDatabase() (boot) lee estas vars.
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';

        // Apps registradas como public/index.php (kernel completo).
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
                // Toleramos apps ausentes (mismo comportamiento que el binario).
            }
        }

        // Boot (define now() y configura la BD vía initDatabase).
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

    /** Despacho con body JSON y headers (kernel real, sin servidor). */
    private function dispatchJson(string $method, string $uri, array $body = [], array $headers = []): array
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'localhost',
            'CONTENT_TYPE' => 'application/json',
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $response = self::$app->handle(new Request([], [], [], [], [], $server, json_encode($body)));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function registroToken(string $email): string
    {
        $stmt = self::$pdo->prepare('SELECT token FROM email_verifications WHERE user_id = (SELECT id FROM users WHERE email = ?) ORDER BY id DESC LIMIT 1');
        $stmt->execute([$email]);
        $hash = $stmt->fetchColumn();
        $this->assertNotFalse($hash, 'Debe existir un token de verificación');

        // El servicio espera el token en claro; se simula el enlace recibido
        // reemplazando el hash por el hash del token "de email" (hash_equals).
        $plain = 'e2e-token-' . bin2hex(random_bytes(8));
        self::$pdo->prepare('UPDATE email_verifications SET token = ? WHERE token = ?')
            ->execute([hash('sha256', $plain), $hash]);
        return $plain;
    }

    private function resetToken(string $email): string
    {
        $stmt = self::$pdo->prepare('SELECT token FROM password_resets WHERE email = ? AND used = 0 ORDER BY id DESC LIMIT 1');
        $stmt->execute([$email]);
        $hash = $stmt->fetchColumn();
        $this->assertNotFalse($hash, 'Debe existir un token de reset');

        $plain = 'e2e-reset-' . bin2hex(random_bytes(8));
        self::$pdo->prepare('UPDATE password_resets SET token = ? WHERE token = ?')
            ->execute([hash('sha256', $plain), $hash]);
        return $plain;
    }

    public function test_e2e_verificacion_publish_reset_e_inscripcion(): void
    {
        // ── Registro (AC-01: el registro emite la verificación) ──
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => 'organizador_e2e',
            'email' => 'org.e2e@test.local',
            'password' => 'clave-inicial-1',
            'first_name' => 'Org',
        ]);
        $this->assertSame(201, $status, 'Registro 201');
        $verifyToken = $this->registroToken('org.e2e@test.local');

        // ── Torneo en borrador ──
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'org.e2e@test.local',
            'password' => 'clave-inicial-1',
        ]);
        $this->assertSame(200, $status, 'Login inicial');
        $orgToken = $body['data']['token'];

        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => 'Copa E2E',
            'format' => 'eliminacion-directa',
            'max_participants' => 8,
            'visibility' => 'publico',
            'sport' => 'Fútbol',
        ], $this->bearer($orgToken));
        $this->assertSame(201, $status, 'Torneo creado');
        $tournamentId = $body['data']['id'];

        // ── AC-01: sin verificar, publish → 403 EMAIL_NOT_VERIFIED ──
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/publish", [], $this->bearer($orgToken));
        $this->assertSame(403, $status, 'Publish sin verificar → 403');
        $this->assertSame('EMAIL_NOT_VERIFIED', $body['code'] ?? null);

        // ── Verificación (AC-01) ──
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/verify-email', ['token' => $verifyToken]);
        $this->assertSame(200, $status, 'Verify 200');
        $this->assertTrue($body['data']['user']['email_verified'] ?? false);

        // Token reutilizado → 400 (AC-01).
        [$status] = $this->dispatchJson('POST', '/api/auth/verify-email', ['token' => $verifyToken]);
        $this->assertSame(400, $status, 'Token reutilizado → 400');

        // ── AC-01: verificado, publish OK ──
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/publish", [], $this->bearer($orgToken));
        $this->assertSame(200, $status, 'Publish verificado → 200');
        $this->assertSame('open', $body['data']['status']);

        // ── AC-02: forgot → reset → login con la nueva contraseña ──
        // Purga el bucket de rate limit (el smoke previo de la suite y el
        // propio flujo acumulan llamadas legítimas en la misma ventana).
        self::$pdo->prepare('DELETE FROM rate_limits')->execute();

        [$status] = $this->dispatchJson('POST', '/api/auth/forgot-password', ['email' => 'org.e2e@test.local']);
        $this->assertSame(200, $status, 'Forgot 200');
        $resetToken = $this->resetToken('org.e2e@test.local');

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/reset-password', [
            'email' => 'org.e2e@test.local',
            'token' => $resetToken,
            'password' => 'clave-nueva-99',
        ]);
        $this->assertSame(200, $status, 'Reset 200');

        [$status] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'org.e2e@test.local',
            'password' => 'clave-inicial-1',
        ]);
        $this->assertSame(401, $status, 'La contraseña vieja ya no funciona');

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'org.e2e@test.local',
            'password' => 'clave-nueva-99',
        ]);
        $this->assertSame(200, $status, 'Login con la nueva contraseña');
        $orgToken = $body['data']['token'];

        // ── AC-03: inscripción con emails transaccionales ──
        // Purga el bucket de rate limit del ambiente de test (el smoke previo
        // de la suite y el propio flujo E2E acumulan llamadas legítimas en la
        // misma ventana de 15 min).
        self::$pdo->prepare('DELETE FROM rate_limits')->execute();

        [$status] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => 'jugador_e2e',
            'email' => 'jug.e2e@test.local',
            'password' => 'clave-jugador-1',
        ]);
        $this->assertSame(201, $status);

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'jug.e2e@test.local',
            'password' => 'clave-jugador-1',
        ]);
        $this->assertSame(200, $status);
        $jugToken = $body['data']['token'];

        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'E2E FC'], $this->bearer($jugToken));
        $this->assertSame(201, $status, 'Equipo del solicitante');
        $teamId = $body['data']['id'];

        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations", ['team_id' => $teamId], $this->bearer($jugToken));
        $this->assertSame(201, $status, 'Solicitud aplicada');
        $registrationId = $body['data']['id'];

        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations/{$registrationId}/decide", ['action' => 'accepted'], $this->bearer($orgToken));
        $this->assertSame(200, $status, 'Solicitud aceptada');

        // Emails transaccionales escritos (driver log).
        $mailDir = dirname(__DIR__, 2) . '/runtime/logs/mail';
        $this->assertNotNull($this->ultimoEmailCon($mailDir, 'org.e2e@test.local'), 'Email al organizador (solicitud)');
        $this->assertNotNull($this->ultimoEmailCon($mailDir, 'jug.e2e@test.local'), 'Email al solicitante (decisión)');
        $this->assertNotNull($this->ultimoEmailCon($mailDir, 'Verifica tu email'), 'Email de verificación del registro');
    }

    private function ultimoEmailCon(string $mailDir, string $texto): ?string
    {
        $files = glob($mailDir . '/*.html') ?: [];
        usort($files, fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file), $texto)) {
                return $file;
            }
        }
        return null;
    }
}