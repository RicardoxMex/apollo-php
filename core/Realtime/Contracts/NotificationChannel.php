<?php

namespace Apollo\Core\Realtime\Contracts;

interface NotificationChannel
{
    public function name(): string;

    public function send(int|string $userId, array $payload): void;
}