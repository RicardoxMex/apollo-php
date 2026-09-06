<?php

namespace Apollo\Core\Realtime\Notifications;

use Apollo\Core\Realtime\Contracts\NotificationChannel;
use Apollo\Core\Realtime\Contracts\NotificationRepository;
use Apollo\Core\Realtime\Notifications\Channels\DatabaseChannel;
use Apollo\Core\Realtime\Notifications\Channels\RealtimeChannel;
use Apollo\Core\Realtime\Support\RealtimeManager;

/**
 * NotificationManager — despacha notificaciones por canales desacoplados.
 *
 *   Notification::send($user, $notification);
 *
 * Una notificación puede persistirse (database), emitirse en realtime, etc.
 * Los canales se registran por nombre en un container inyectable.
 */
class NotificationManager
{
    /** @var NotificationChannel[] */
    private array $channels = [];

    /** @var array channelName => bool */
    private array $enabled = [];

    public function __construct(
        ?NotificationRepository $repository = null,
        ?RealtimeManager $realtime = null
    ) {
        if ($repository) {
            $this->register(new DatabaseChannel($repository));
        }

        if ($realtime) {
            $this->register(new RealtimeChannel($realtime));
        }
    }

    public function register(NotificationChannel $channel): void
    {
        $this->channels[$channel->name()] = $channel;
        $this->enabled[$channel->name()] = true;
    }

    public function enable(string $channelName): void
    {
        $this->enabled[$channelName] = true;
    }

    public function disable(string $channelName): void
    {
        $this->enabled[$channelName] = false;
    }

    /**
     * Enviar una notificación a un usuario por los canales que define.
     *
     * @param int|string $userId
     */
    public function send(int|string $userId, Notification $notification): array
    {
        $payload = $notification->toArray();
        $payload['notification_id'] = $notification->id();
        $payload['channel'] = '';

        $sent = [];

        foreach ($notification->channels() as $channelName) {
            $channel = $this->channels[$channelName] ?? null;

            if (!$channel || !($this->enabled[$channelName] ?? false)) {
                continue;
            }

            $channel->send($userId, $payload);
            $sent[] = $channelName;
        }

        return $sent;
    }

    public function channels(): array
    {
        return array_keys($this->channels);
    }
}