<?php

namespace Apollo\Core\Realtime\Contracts;

interface NotificationRepository
{
    public function create(array $data): string;

    public function find(string $id): ?array;

    public function forUser(int|string $userId, array $filters = []): array;

    /**
     * Notificaciones del usuario con id estrictamente mayor a $afterId,
     * ordenadas por id ascendente, limitadas a $limit. Usado por el
     * servidor WebSocket para el polling cross-process (D4-revisado).
     */
    public function forUserAfterId(int|string $userId, string $afterId, int $limit = 50): array;

    public function markAsRead(string $id): bool;

    public function delete(string $id): bool;
}