<?php

namespace Apollo\Core\Realtime\Channels;

/**
 * Canal privado: requiere autorización (ticket HMAC del backend) antes
 * de permitir la suscripción. Nunca confiar en datos del cliente.
 */
class PrivateChannel extends Channel
{
    protected string $type = 'private';

    /** @var array channel => [connectionId => userId] */
    protected array $authorizedUsers = [];

    public function authorize(string $connectionId, int|string $userId): void
    {
        $this->authorizedUsers[$connectionId] = $userId;
    }

    public function isAuthorized(string $connectionId): bool
    {
        return isset($this->authorizedUsers[$connectionId]);
    }

    public function userIdFor(string $connectionId): int|string|null
    {
        return $this->authorizedUsers[$connectionId] ?? null;
    }
}