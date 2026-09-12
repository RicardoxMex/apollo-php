<?php

namespace Apollo\Core\Realtime\Connections;

use Apollo\Core\Realtime\Channels\ChannelManager;

/**
 * Una conexión WebSocket: id, usuario asociado (si autenticado) y los
 * canales a los que está suscrita.
 */
class Connection
{
    private string $id;
    private int|string|null $userId;
    private ChannelManager $channels;
    private array $subscribedChannels = [];
    private int $lastSeen;
    private $resource = null; // en el servidor: $server->push($fd, ...)

    public function __construct(string $id, int|string|null $userId, ChannelManager $channels)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->channels = $channels;
        $this->lastSeen = time();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): int|string|null
    {
        return $this->userId;
    }

    public function setUserId(int|string|null $userId): void
    {
        $this->userId = $userId;
    }

    public function attachResource($resource): void
    {
        $this->resource = $resource;
    }

    public function resource()
    {
        return $this->resource;
    }

    public function touch(): void
    {
        $this->lastSeen = time();
    }

    public function lastSeen(): int
    {
        return $this->lastSeen;
    }

    public function isExpired(int $timeoutSeconds): bool
    {
        return (time() - $this->lastSeen) > $timeoutSeconds;
    }

    public function subscribe(string $channelName): void
    {
        $this->channels->subscribe($channelName, $this);
        $this->subscribedChannels[$channelName] = true;
    }

    public function unsubscribe(string $channelName): void
    {
        $this->channels->unsubscribe($channelName, $this);
        unset($this->subscribedChannels[$channelName]);
    }

    public function channels(): array
    {
        return array_keys($this->subscribedChannels);
    }

    public function send(array $payload): bool
    {
        $resource = $this->resource;

        if (is_callable($resource)) {
            try {
                $resource($payload);
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }
}