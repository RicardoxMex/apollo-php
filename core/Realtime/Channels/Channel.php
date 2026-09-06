<?php

namespace Apollo\Core\Realtime\Channels;

use Apollo\Core\Realtime\Connections\ConnectionManager;

abstract class Channel
{
    protected string $name;
    protected string $type; // public | private | presence
    protected array $subscribers = []; // connectionId => Connection

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function subscribe($connection): void
    {
        $this->subscribers[$connection->id()] = $connection;
    }

    public function unsubscribe($connection): void
    {
        unset($this->subscribers[$connection->id()]);
    }

    public function hasSubscriber($connection): bool
    {
        return isset($this->subscribers[$connection->id()]);
    }

    public function getSubscribers(): array
    {
        return array_values($this->subscribers);
    }

    public function subscriberCount(): int
    {
        return count($this->subscribers);
    }

    /**
     * Enviar payload a todos los suscriptores del canal.
     */
    public function broadcast(array $payload): void
    {
        foreach ($this->subscribers as $connection) {
            $connection->send($payload);
        }
    }
}