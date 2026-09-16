<?php

namespace Apollo\Core\Middleware;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Closure;
use PDO;

/**
 * RateLimitMiddleware — limita el número de peticiones por ventana.
 *
 *   $router->post('/login', 'AuthController@login')->middleware(['rate_limit.login']);
 *
 * Almacena los intentos en la tabla `rate_limits` (migración 006), clave única
 * (key, type). Config: config('auth.rate_limit') → max_attempts, window, lockout.
 *
 * Cuando se supera el límite devuelve 429 con Retry-After (segundos restantes).
 * Por defecto limita por IP; con keyBy 'user' usa el id del usuario autenticado.
 */
class RateLimitMiddleware
{
    public function __construct(
        private string $type = 'api',
        private string $keyBy = 'ip',
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        $config = config('auth.rate_limit') ?? [];

        // Flag de desarrollo (DISABLE_RATE_LIMIT=true): no bloquea localmente.
        if (($config['enabled'] ?? true) === false) {
            return $next($request);
        }

        $maxAttempts = max(1, (int) ($config['max_attempts'] ?? 20));
        $window = max(1, (int) ($config['window'] ?? 900));
        $lockout = max(1, (int) ($config['lockout_duration'] ?? $window));

        try {
            return $this->enforce($request, $next, $maxAttempts, $window, $lockout);
        } catch (\Throwable $e) {
            // Fail-open: sin BD (tests, migración pendiente) el acceso no se bloquea,
            // pero queda registrado para no silenciar el problema en producción.
            error_log("RateLimitMiddleware sin BD disponible ({$this->type}): " . $e->getMessage());
            return $next($request);
        }
    }

    private function enforce(Request $request, Closure $next, int $maxAttempts, int $window, int $lockout)
    {
        $key = $this->resolveKey($request);
        $now = date('Y-m-d H:i:s');
        $pdo = DatabaseManager::getConnection();

        $stmt = $pdo->prepare('SELECT * FROM rate_limits WHERE `key` = ? AND `type` = ?');
        $stmt->execute([$key, $this->type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->insert($pdo, $key, $now, $window);
            return $next($request);
        }

        // ¿Ventana expirada? Reinicia el contador.
        $windowStart = strtotime($row['window_start']);
        if ($windowStart === false || (time() - $windowStart) >= $window) {
            $this->reset($pdo, $key, $now, $window);
            return $next($request);
        }

        // ¿Lockout activo? 429 con el tiempo restante.
        if ((int) $row['attempts'] >= $maxAttempts) {
            $expires = strtotime($row['expires_at']) ?: time() + $lockout;
            $retryAfter = max(1, $expires - time());
            return Response::json([
                'error' => 'Too Many Requests',
                'message' => 'Demasiados intentos. Inténtalo de nuevo en unos segundos.',
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }

        // Dentro de la ventana y bajo el límite: cuenta e incrementa.
        $this->increment($pdo, $key);
        return $next($request);
    }

    private function resolveKey(Request $request): string
    {
        if ($this->keyBy === 'user' && $request->user()) {
            return 'user:' . $request->user()->id;
        }
        return 'ip:' . $request->ip();
    }

    private function insert(PDO $pdo, string $key, string $now, int $window): void
    {
        $pdo->prepare(
            "INSERT INTO rate_limits (`key`, `type`, `attempts`, `window_start`, `expires_at`, `created_at`, `updated_at`)
             VALUES (?, ?, 1, ?, ?, ?, ?)"
        )->execute([$key, $this->type, $now, date('Y-m-d H:i:s', time() + $window), $now, $now]);
    }

    private function reset(PDO $pdo, string $key, string $now, int $window): void
    {
        $pdo->prepare(
            "UPDATE rate_limits SET attempts = 1, window_start = ?, expires_at = ?, updated_at = ?
             WHERE `key` = ? AND `type` = ?"
        )->execute([$now, date('Y-m-d H:i:s', time() + $window), $now, $key, $this->type]);
    }

    private function increment(PDO $pdo, string $key): void
    {
        $pdo->prepare(
            "UPDATE rate_limits SET attempts = attempts + 1, updated_at = ?
             WHERE `key` = ? AND `type` = ?"
        )->execute([date('Y-m-d H:i:s'), $key, $this->type]);
    }
}