<?php

namespace Apollo\Core\Realtime\Auth;

use Apollo\Core\Container\Container;
use Apps\ApolloAuth\Services\AuthService;

/**
 * Autenticación de la conexión WebSocket en el handshake.
 *
 * Extrae el token del query `?token=<jwt>` o de la cabecera `Authorization: Bearer …`,
 * valida con `AuthService::authenticateFromToken` (mecanismo real: JWT + user_sessions)
 * y devuelve el `user_id` autenticado. El servidor NUNCA confía en un `user_id`
 * enviado por el cliente: el resultado aquí es la única fuente de verdad.
 *
 * Diseñado para ser invocado desde `onWebSocketConnect` (Workerman) antes de aceptar
 * la conexión; cualquier fallo devuelve `null` y la conexión debe cerrarse.
 */
class ConnectionAuthenticator
{
    private ?AuthService $authService = null;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService;
    }

    /**
     * Autentica a partir de los datos del handshake HTTP (query + headers).
     *
     * @param array $get      $_GET equivalente (parámetros query string)
     * @param array $headers  Cabeceras HTTP del handshake (en minúsculas, valores planos)
     * @return int|null       user_id si el token es válido, `null` en caso contrario
     */
    public function authenticate(array $get, array $headers): ?int
    {
        $token = $this->extractToken($get, $headers);

        if ($token === null || $token === '') {
            return null;
        }

        try {
            $user = $this->getAuthService()->authenticateFromToken($token);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$user || !isset($user->id)) {
            return null;
        }

        return (int) $user->id;
    }

    /**
     * Extrae el token del query o de la cabecera Authorization.
     */
    private function extractToken(array $get, array $headers): ?string
    {
        // 1) Query string: ?token=<jwt>  (los navegadores no permiten Authorization en WS handshake)
        if (isset($get['token']) && is_string($get['token']) && $get['token'] !== '') {
            return $get['token'];
        }

        // 2) Bearer token en cabecera (clientes Node/CLI que sí la pueden fijar)
        $authorization = $this->headerValue($headers, 'authorization');

        if ($authorization !== null && stripos($authorization, 'Bearer ') === 0) {
            $token = trim(substr($authorization, 7));

            if ($token !== '') {
                return $token;
            }
        }

        return null;
    }

    /**
     * Busca una cabecera de forma case-insensitive.
     */
    private function headerValue(array $headers, string $name): ?string
    {
        $nameLower = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $nameLower) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return null;
    }

    private function getAuthService(): AuthService
    {
        if ($this->authService !== null) {
            return $this->authService;
        }

        $container = Container::getInstance();

        if ($container->has(AuthService::class)) {
            $this->authService = $container->make(AuthService::class);
        } else {
            $this->authService = new AuthService();
        }

        return $this->authService;
    }
}
