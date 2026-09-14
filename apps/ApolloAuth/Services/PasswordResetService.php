<?php

namespace Apps\ApolloAuth\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Apps\ApolloAuth\Models\User;
use PDO;

/**
 * Password reset (D3):
 * - Tabla `password_resets` (migración 005) con token hashado (SHA-256),
 *   expiración de 30 minutos, un solo uso.
 * - forgot-password responde siempre 200 (no revela la existencia del email);
 *   el rate limit vive en la ruta (rate_limit.login).
 * - reset-password valida token+email, aplica la nueva contraseña y revoca
 *   todas las sesiones activas del usuario.
 */
class PasswordResetService
{
    public const TOKEN_TTL = 1800; // 30 minutos (D3)

    public function __construct()
    {
    }

    private function pdo(): PDO
    {
        return DatabaseManager::getConnection();
    }

    /**
     * Solicita el reset. Sin efectos visibles si el email no existe (anti
     * enumeración): solo se emite el token y el email cuando hay usuario.
     */
    public function forgot(string $email, ?Request $request = null): void
    {
        $email = strtolower(trim($email));
        $user = User::where('email', $email)->first();
        if (!$user) {
            return;
        }

        $pdo = $this->pdo();
        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');

        // Revoca tokens previos sin usar del mismo email.
        $pdo->prepare("UPDATE password_resets SET used = 1, used_at = ? WHERE email = ? AND used = 0")
            ->execute([$now, $email]);

        $pdo->prepare(
            'INSERT INTO password_resets (email, token, expires_at, used, ip_address, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)'
        )->execute([
            $email,
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() + self::TOKEN_TTL),
            $request?->ip() ?? '127.0.0.1',
            $now,
            $now,
        ]);

        $this->sendResetEmail($user, $token);
    }

    /**
     * Aplica el reset. Lanza RuntimeException 400 ante token inválido/expirado.
     */
    public function reset(string $email, string $token, string $password, ?Request $request = null): User
    {
        $email = strtolower(trim($email));
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM password_resets WHERE email = ? AND used = 0'
        );
        $stmt->execute([$email]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record || !hash_equals((string) $record['token'], hash('sha256', $token))) {
            throw new \RuntimeException('El enlace de recuperación no es válido', 400);
        }
        if (strtotime($record['expires_at']) < time()) {
            throw new \RuntimeException('El enlace de recuperación caducó. Solicita uno nuevo.', 400);
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            throw new \RuntimeException('El usuario ya no existe', 400);
        }

        $now = date('Y-m-d H:i:s');
        $pdo = $this->pdo();

        // Nueva contraseña (el modelo la hashea al asignar) + token usado.
        $pdo->beginTransaction();
        try {
            $user->update(['password' => $password]);
            $pdo->prepare('UPDATE password_resets SET used = 1, used_at = ? WHERE id = ?')
                ->execute([$now, $record['id']]);
            // Seguridad (D3): revoca todas las sesiones activas del usuario.
            $pdo->prepare('UPDATE user_sessions SET is_revoked = 1 WHERE user_id = ?')
                ->execute([$user->id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $user;
    }

    /** Email de recuperación con enlace al frontend (D1/D3). */
    private function sendResetEmail(User $user, string $token): void
    {
        try {
            $frontend = rtrim((string) config('mail.frontend_url', 'http://localhost:3000'), '/');
            mailer()->sendTemplate(
                'reset_password',
                $user->email,
                [
                    'name' => $user->display_name,
                    'link' => "{$frontend}/reset-password?token={$token}&email=" . rawurlencode($user->email),
                ],
                $user->display_name,
            );
        } catch (\Throwable $e) {
            error_log('Email de reset fallido: ' . $e->getMessage());
        }
    }
}