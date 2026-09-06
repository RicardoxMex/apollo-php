<?php

namespace Apollo\Core\Realtime\Auth;

use Apollo\Core\Realtime\Channels\ChannelManager;
use Apollo\Core\Realtime\Support\RealtimeConfig;

/**
 * Autorización de canales privados / presence.
 *
 * El cliente nunca decide el acceso: solicita un ticket firmado al backend
 * (POST /realtime/auth o el gate de la app) y el servidor lo valida con el
 * app_secret antes de permitir la suscripción.
 */
class ChannelAuthenticator
{
    private RealtimeConfig $config;
    private $authorizeCallback = null;

    public function __construct(RealtimeConfig $config)
    {
        $this->config = $config;
        $this->authorizeCallback = $config->authorizeCallback();
    }

    /**
     * ¿El canal es público? (los públicos no requieren ticket)
     */
    public function isPublic(string $channelName): bool
    {
        return ChannelManager::channelType($channelName) === ChannelManager::PUBLIC;
    }

    /**
     * Decisión de la aplicación: ¿este usuario puede acceder al canal?
     */
    public function authorize(string $channelName, int|string $userId, array $requestData = []): bool
    {
        if ($this->isPublic($channelName)) {
            return true;
        }

        if ($this->authorizeCallback) {
            return (bool) ($this->authorizeCallback)($channelName, $userId, $requestData);
        }

        // Sin callback de la app: default SEGURO = rechazar (el cliente solo
        // debe acceder a canales privados con un ticket firmado por el backend).
        return false;
    }

    /**
     * Ticket HMAC: app_secret → hmac(sha256, "{channel}:{userId}:{payloadExpirable}")
     */
    public function sign(string $channelName, int|string $userId, string $signatureInput = ''): string
    {
        if (empty($this->config->appSecret())) {
            throw new \RuntimeException('REALTIME_APP_SECRET no está configurado (necesario para canales privados)');
        }

        return hash_hmac('sha256', "{$channelName}:{$userId}:{$signatureInput}", $this->config->appSecret());
    }

    /**
     * Validar el ticket presentado por el cliente al suscribirse.
     */
    public function verifyTicket(
        string $channelName,
        int|string $userId,
        string $ticket,
        string $signatureInput = ''
    ): bool {
        return hash_equals($this->sign($channelName, $userId, $signatureInput), $ticket);
    }
}