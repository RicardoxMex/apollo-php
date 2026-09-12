<?php

namespace Apollo\Core\Realtime\Notifications;

use Apollo\Core\Database\QueryBuilder;
use Apollo\Core\Realtime\Contracts\NotificationRepository;

/**
 * Repositorio de notificaciones sobre la tabla 'notifications' (MySQL/SQLite).
 * La comunicación realtime NUNCA pasa por la base: aquí solo persiste.
 */
class MySqlNotificationRepository implements NotificationRepository
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \Apollo\Core\Database\Connection\DatabaseManager::getConnection();
    }

    private function table(): QueryBuilder
    {
        return new QueryBuilder($this->pdo, 'notifications');
    }

    public function create(array $data): string
    {
        $id = 'notif_' . bin2hex(random_bytes(8));

        $this->table()->insert([
            'id' => $id,
            'user_id' => $data['user_id'],
            'type' => $data['type'] ?? 'notification',
            'title' => $data['title'] ?? '',
            'message' => $data['message'] ?? '',
            'data' => json_encode($data['data'] ?? [], JSON_UNESCAPED_UNICODE),
            'read_at' => $data['read_at'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    public function find(string $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function forUser(int|string $userId, array $filters = []): array
    {
        $query = new QueryBuilder($this->pdo, 'notifications');

        $query->where('user_id', $userId);

        if (isset($filters['unread']) && $filters['unread']) {
            $query->where('read_at', null);
        }

        if (isset($filters['limit'])) {
            $query->limit((int) $filters['limit']);
        }

        $rows = $query->orderBy('created_at', 'DESC')->get();

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function markAsRead(string $id): bool
    {
        return $this->table()->where('id', $id)->update([
            'read_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    public function forUserAfterId(int|string $userId, string $afterId, int $limit = 50): array
    {
        $query = new QueryBuilder($this->pdo, 'notifications');

        $query->where('user_id', $userId);
        $query->where('id', '>', $afterId);
        $query->orderBy('id', 'ASC');
        $query->limit($limit);

        $rows = $query->get();

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function delete(string $id): bool
    {
        return $this->table()->where('id', $id)->delete() > 0;
    }

    private function hydrate(array $row): array
    {
        $row['data'] = is_string($row['data'] ?? null)
            ? json_decode($row['data'], true) ?? []
            : ($row['data'] ?? []);

        return $row;
    }
}