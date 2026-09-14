<?php

namespace Apps\ApolloAuth\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Apps\ApolloAuth\Models\User;
use PDO;

/**
 * Verificación de email (D2):
 * - token hashado (SHA-256) en `email_verifications`, expiración 24 h, un solo uso.
 * - El registro emite el email; el reenvío está limitado por rate limit en ruta.
 * - El envío es best-effort (el Mailer nunca rompe la petición).
 */
class VerificationService
{
    public const TOKEN_TTL = 86400; // 24 horas (D2)

    public function __construct()
    {
    }

    private function pdo(): PDO
    {
        return DatabaseManager::getConnection();
    }

    /**
     * Genera el token, revoca los anteriores sin usar del usuario y envía el
     * email de verificación. Devuelve el token en claro (para el enlace).
     */
    public function issue(User $user, ?Request $request = null): string
    {
        $token = bin2hex(random_bytes(32));
        $pdo = $this->pdo();

        $pdo->prepare("UPDATE email_verifications SET used = 1, used_at = ? WHERE user_id = ? AND used = 0")
            ->execute([date('Y-m-d H:i:s'), $user->id]);

        $pdo->prepare(
            'INSERT INTO email_verifications (user_id, token, expires_at, used, ip_address, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)'
        )->execute([
            $user->id,
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() + self::TOKEN_TTL),
            $request?->ip() ?? '127.0.0.1',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);

        $this->sendVerificationEmail($user, $token);
        return $token;
    }

    /**
     * Verifica el token (hash, expiración, un solo uso) y marca el email.
     * Lanza RuntimeException 400 ante token inválido/expirado/usado.
     */
    public function verify(string $token, ?Request $request = null): User
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM email_verifications WHERE token = ? AND used = 0'
        );
        $stmt->execute([hash('sha256', $token)]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            throw new \RuntimeException('El enlace de verificación no es válido', 400);
        }
        if (strtotime($record['expires_at']) < time()) {
            throw new \RuntimeException('El enlace de verificación caducó. Solicita uno nuevo.', 400);
        }

        $user = User::find((int) $record['user_id']);
        if (!$user) {
            throw new \RuntimeException('El usuario ya no existe', 400);
        }

        $now = date('Y-m-d H:i:s');
        $this->pdo()->prepare('UPDATE email_verifications SET used = 1, used_at = ? WHERE id = ?')
            ->execute([$now, $record['id']]);

        if (!$user->hasVerifiedEmail()) {
            // Marcado directo (evita la dependencia del helper now() en tests).
            $this->pdo()->prepare('UPDATE users SET email_verified_at = ? WHERE id = ?')
                ->execute([$now, $user->id]);
            $user->email_verified_at = $now;
        }

        return $user;
    }

    /**
     * Reenvío del email de verificación (rate limited en la ruta).
     */
    public function resend(User $user, ?Request $request = null): void
    {
        if ($user->hasVerifiedEmail()) {
            throw new \RuntimeException('Tu email ya está verificado', 409);
        }
        $this->issue($user, $request);
    }

    /** Email de verificación con enlace al frontend (D1/D2). */
    private function sendVerificationEmail(User $user, string $token): void
    {
        try {
            $frontend = rtrim((string) config('mail.frontend_url', 'http://localhost:3000'), '/');
            mailer()->sendTemplate(
                'verification',
                $user->email,
                ['name' => $user->display_name, 'link' => "{$frontend}/verify-email?token={$token}"],
                $user->display_name,
            );
        } catch (\Throwable $e) {
            error_log('Email de verificación fallido: ' . $e->getMessage());
        }
    }
}