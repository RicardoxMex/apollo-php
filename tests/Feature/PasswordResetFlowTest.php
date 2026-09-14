<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apps\ApolloAuth\Models\User;
use Apps\ApolloAuth\Services\PasswordResetService;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Password reset (EMAIL-03, D3): forgot emite token hashado con expiración de
 * 30 min (respuesta uniforme para emails inexistentes), reset valida token,
 * aplica la nueva contraseña y revoca las sesiones activas; token reutilizado
 * o caducado → 400. SQLite :memory: con migraciones reales.
 */
class PasswordResetFlowTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static PasswordResetService $resets;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite no disponible');
        }

        new \Apollo\Core\Application(dirname(__DIR__, 2));
        \app('config');

        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        DatabaseManager::disconnect();

        self::$pdo = DatabaseManager::getConnection();

        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }

        self::$resets = new PasswordResetService();
    }

    private function createUser(string $username, string $email): User
    {
        $stmt = self::$pdo->prepare("INSERT INTO users (username, email, password, status) VALUES (?, ?, ?, 'active')");
        $stmt->execute([$username, $email, password_hash('secret', PASSWORD_DEFAULT)]);
        return User::find((int) self::$pdo->lastInsertId());
    }

    public function test_forgot_emits_hashed_token_with_expiry(): void
    {
        $user = $this->createUser('reset1', 'reset1@test.local');
        self::$resets->forgot($user->email);

        $stmt = self::$pdo->prepare('SELECT * FROM password_resets WHERE email = ? AND used = 0');
        $stmt->execute([$user->email]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($record, 'Existe un token sin usar');
        // Token hashado, nunca en claro; expiración ≤ 30 min.
        $this->assertSame(64, strlen((string) $record['token']));
        $this->assertLessThanOrEqual(30 * 60, strtotime($record['expires_at']) - time());
    }

    public function test_forgot_unknown_email_is_silent(): void
    {
        // No debe lanzar ni dejar registros: anti enumeración (D3).
        self::$resets->forgot('no-existe@test.local');
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE email = ?');
        $stmt->execute(['no-existe@test.local']);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function test_reset_changes_password_and_revokes_sessions(): void
    {
        $user = $this->createUser('reset2', 'reset2@test.local');
        self::$resets->forgot($user->email);

        $stmt = self::$pdo->prepare('SELECT token FROM password_resets WHERE email = ? AND used = 0');
        $stmt->execute([$user->email]);
        $storedHash = $stmt->fetchColumn();

        // Sesión activa previa (se debe revocar al resetear).
        self::$pdo->prepare("INSERT INTO user_sessions (user_id, token_id, expires_at, last_used_at, is_revoked) VALUES (?, 'jti-test', ?, ?, 0)")
            ->execute([$user->id, date('Y-m-d H:i:s', time() + 3600), date('Y-m-d H:i:s')]);

        // El servicio recibe el token en claro; nosotros lo reconstruimos desde el hash.
        $plainToken = $this->tokenDesdeHash((string) $storedHash);

        $updated = self::$resets->reset($user->email, $plainToken, 'nueva-clave-123');
        $this->assertTrue($updated->verifyPassword('nueva-clave-123'));
        $this->assertFalse($updated->verifyPassword('secret'));

        // Token marcado usado y sesiones revocadas.
        $stmt = self::$pdo->prepare('SELECT used FROM password_resets WHERE email = ?');
        $stmt->execute([$user->email]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $stmt = self::$pdo->prepare('SELECT is_revoked FROM user_sessions WHERE token_id = ?');
        $stmt->execute(['jti-test']);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_reset_with_used_token_throws_400(): void
    {
        $user = $this->createUser('reset3', 'reset3@test.local');
        self::$resets->forgot($user->email);

        $stmt = self::$pdo->prepare('SELECT token FROM password_resets WHERE email = ? AND used = 0');
        $stmt->execute([$user->email]);
        $plainToken = $this->tokenDesdeHash((string) $stmt->fetchColumn());

        self::$resets->reset($user->email, $plainToken, 'clave-nueva-1');
        try {
            self::$resets->reset($user->email, $plainToken, 'clave-nueva-2');
            $this->fail('El token reutilizado debe fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame(400, $e->getCode());
        }
    }

    public function test_reset_with_invalid_token_throws_400(): void
    {
        $user = $this->createUser('reset4', 'reset4@test.local');
        self::$resets->forgot($user->email);

        try {
            self::$resets->reset($user->email, 'token-inventado', 'clave-nueva-3');
            $this->fail('Token inválido debe fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame(400, $e->getCode());
        }
    }

    public function test_reset_with_expired_token_throws_400(): void
    {
        $user = $this->createUser('reset5', 'reset5@test.local');
        self::$resets->forgot($user->email);

        // Expira el token forzando la fecha.
        self::$pdo->prepare('UPDATE password_resets SET expires_at = ? WHERE email = ?')
            ->execute([date('Y-m-d H:i:s', time() - 60), $user->email]);

        $stmt = self::$pdo->prepare('SELECT token FROM password_resets WHERE email = ? AND used = 0');
        $stmt->execute([$user->email]);
        $plainToken = $this->tokenDesdeHash((string) $stmt->fetchColumn());

        try {
            self::$resets->reset($user->email, $plainToken, 'clave-nueva-4');
            $this->fail('Token caducado debe fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('caducó', $e->getMessage());
        }
    }

    /** Reconstruye el token en claro que el servicio espera (hash_equals). */
    private function tokenDesdeHash(string $hash): string
    {
        // En un flujo real el token viaja por email; aquí se deriva del hash
        // buscando el preimagen no es posible → usamos un token arbitrario y
        // actualizamos el hash de la BD para simular el enlace emitido.
        $plain = 'token-real-del-email-' . bin2hex(random_bytes(8));
        self::$pdo->prepare('UPDATE password_resets SET token = ? WHERE token = ?')
            ->execute([hash('sha256', $plain), $hash]);
        return $plain;
    }
}