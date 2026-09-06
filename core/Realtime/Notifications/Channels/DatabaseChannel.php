<?php

namespace Apollo\Core\Realtime\Notifications\Channels;

use Apollo\Core\Realtime\Contracts\NotificationChannel;

/**
 * Canal 'database': persiste la notificación vía NotificationRepository.
 */
class DatabaseChannel implements NotificationChannel
{
    private \Apollo\Core\Realtime\Contracts\NotificationRepository $repository;

    public function __construct(\Apollo\Core\Realtime\Contracts\NotificationRepository $repository)
    {
        $this->repository = $repository;
    }

    public function name(): string
    {
        return 'database';
    }

    public function send(int|string $userId, array $payload): void
    {
        $this->repository->create([
            'user_id' => $userId,
            'type' => $payload['type'] ?? 'notification',
            'title' => $payload['title'] ?? '',
            'message' => $payload['message'] ?? '',
            'data' => $payload['data'] ?? [],
            'read_at' => null,
        ]);
    }
}