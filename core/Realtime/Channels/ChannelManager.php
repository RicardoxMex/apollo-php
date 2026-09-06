<?php

namespace Apollo\Core\Realtime\Channels;

/**
 * Registro de canales (públicos, privados y presence).
 */
class ChannelManager
{
    /** @var Channel[] */
    private array $channels = [];

    public const PUBLIC = 'public';
    public const PRIVATE = 'private';
    public const PRESENCE = 'presence';

    public static function channelType(string $channelName): string
    {
        if (str_starts_with($channelName, 'presence-')) {
            return self::PRESENCE;
        }

        if (str_starts_with($channelName, 'private-')) {
            return self::PRIVATE;
        }

        return self::PUBLIC;
    }

    public function make(string $channelName): Channel
    {
        return match (self::channelType($channelName)) {
            self::PRESENCE => new PresenceChannel($channelName),
            self::PRIVATE => new PrivateChannel($channelName),
            default => new PublicChannel($channelName),
        };
    }

    public function get(string $channelName): ?Channel
    {
        return $this->channels[$channelName] ?? null;
    }

    public function getOrCreate(string $channelName): Channel
    {
        if (!isset($this->channels[$channelName])) {
            $this->channels[$channelName] = $this->make($channelName);
        }

        return $this->channels[$channelName];
    }

    public function subscribe(string $channelName, $connection): Channel
    {
        $channel = $this->getOrCreate($channelName);
        $channel->subscribe($connection);

        return $channel;
    }

    public function unsubscribe(string $channelName, $connection): void
    {
        $channel = $this->get($channelName);

        if ($channel) {
            $channel->unsubscribe($connection);

            // Limpiar canales sin suscriptores
            if ($channel->subscriberCount() === 0) {
                unset($this->channels[$channelName]);
            }
        }
    }

    public function hasSubscriber(string $channelName, $connection): bool
    {
        $channel = $this->get($channelName);

        return $channel?->hasSubscriber($connection) ?? false;
    }

    public function getSubscribers(string $channelName): array
    {
        return $this->get($channelName)?->getSubscribers() ?? [];
    }

    public function broadcast(string $channelName, array $payload): void
    {
        $this->get($channelName)?->broadcast($payload);
    }

    public function channels(): array
    {
        return $this->channels;
    }
}