<?php

namespace Apollo\Core\Realtime\Contracts;

interface NotificationRepository
{
    public function create(array $data): string;

    public function find(string $id): ?array;

    public function forUser(int|string $userId, array $filters = []): array;

    public function markAsRead(string $id): bool;

    public function delete(string $id): bool;
}