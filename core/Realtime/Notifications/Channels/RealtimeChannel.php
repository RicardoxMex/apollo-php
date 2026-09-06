<?php

namespace Apollo\Core\Realtime\Notifications\Channels;

use Apollo\Core\Realtime\Contracts\NotificationChannel;

/**
 * Canal 'realtime': emite un evento al canal privado del usuario
 * (private-user.{id}) para que el cliente reciba la notificación al instante.
 */
class RealtimeChannel implements NotificationChannel
{
    private \Apollo\Core\Realtime\Support\RealtimeManager $realtime;

    public function __construct(\Apollo\Core\Realtime\Support\RealtimeManager $realtime)
    {
        $this->realtime = $realtime;
    }

    public function name(): string
    {
        return 'realtime';
    }

    public function send(int|string $userId, array $payload): void
    {
        $channel = "private-user.{$userId}";

        $data = [
            'notification' => [
                'type' => $payload['type'] ?? 'notification',
                'title' => $payload['title'] ?? '',
                'message' => $payload['message'] ?? '',
                'data' => $payload['data'] ?? [],
            ],
        ];

        $this->realtime->broadcast($channel, 'notification.received', $data);
    }
}