<?php

namespace Apollo\Core\Realtime\Connections;

use Apollo\Core\Realtime\Channels\ChannelManager;

/**
 * Administra conexiones activas del servidor WebSocket.
 *
 * Relaciones mantenidas:
 *   connection_id → Connection
 *   user_id → [connection_ids]
 *   connection_id → [channel_names]   (vía Connection)
 *   channel → [connection_ids]        (vía ChannelManager)
 */
class ConnectionManager
{
    private ChannelManager $channels;

    /** @var Connection[] */
    private array $connections = [];

    /** @var array userId => [connectionId => true] */
    private array $userConnections = [];

    private int $maxConnections;

    public function __construct(?ChannelManager $channels = null, int $maxConnections = 10000)
    {
        $this->channels = $channels ?? new ChannelManager();
        $this->maxConnections = $maxConnections;
    }

    public function channels(): ChannelManager
    {
        return $this->channels;
    }

    public function connect(int|string|null $userId = null): Connection
    {
        if (count($this->connections) >= $this->maxConnections) {
            throw new \OverflowException("Max connections reached ({$this->maxConnections})");
        }

        $id = 'conn_' . bin2hex(random_bytes(8));
        $connection = new Connection($id, $userId, $this->channels);

        $this->connections[$id] = $connection;

        if ($userId !== null) {
            $this->userConnections[$userId][$id] = true;
        }

        return $connection;
    }

    public function disconnect(string $connectionId): void
    {
        $connection = $this->connections[$connectionId] ?? null;

        if (!$connection) {
            return;
        }

        // Quitar de todos los canales
        foreach ($connection->channels() as $channelName) {
            $this->channels->unsubscribe($channelName, $connection);
        }

        $userId = $connection->userId();

        unset($this->connections[$connectionId]);

        if ($userId !== null) {
            unset($this->userConnections[$userId][$connectionId]);
            if (empty($this->userConnections[$userId])) {
                unset($this->userConnections[$userId]);
            }
        }
    }

    public function getConnection(string $connectionId): ?Connection
    {
        return $this->connections[$connectionId] ?? null;
    }

    public function getConnections(): array
    {
        return array_values($this->connections);
    }

    public function hasConnection(string $connectionId): bool
    {
        return isset($this->connections[$connectionId]);
    }

    public function send(string $connectionId, array $payload): bool
    {
        $connection = $this->getConnection($connectionId);

        if (!$connection) {
            return false;
        }

        $connection->send($payload);

        return true;
    }

    /**
     * Entrega un payload a TODAS las conexiones autenticadas del usuario.
     * Devuelve el número de conexiones a las que se entregó.
     */
    public function sendToUser(int|string $userId, array $payload): int
    {
        $connections = $this->connectionsForUser($userId);
        $delivered = 0;

        foreach ($connections as $connection) {
            if ($connection->send($payload)) {
                $delivered++;
            }
        }

        return $delivered;
    }

    /**
     * Barredor de conexiones inactivas: desconecta las que llevan más de
     * `$timeoutSeconds` sin actividad. Devuelve el número de conexiones eliminadas.
     */
    public function sweep(int $timeoutSeconds): int
    {
        $removed = 0;
        $now = time();

        foreach ($this->connections as $id => $connection) {
            if (($now - $connection->lastSeen()) > $timeoutSeconds) {
                $this->disconnect($id);
                $removed++;
            }
        }

        return $removed;
    }

    public function broadcast(array $payload, ?array $exceptConnectionIds = []): void
    {
        foreach ($this->connections as $id => $connection) {
            if (in_array($id, $exceptConnectionIds, true)) {
                continue;
            }
            $connection->send($payload);
        }
    }

    public function connectionsForUser(int|string $userId): array
    {
        $ids = array_keys($this->userConnections[$userId] ?? []);

        return array_map(fn($id) => $this->connections[$id], $ids);
    }

    public function hasUser(int|string $userId): bool
    {
        return !empty($this->userConnections[$userId] ?? []);
    }

    /**
     * IDs de los usuarios con al menos una conexión activa.
     *
     * @return array<int, int|string>
     */
    public function getUserIds(): array
    {
        return array_keys($this->userConnections);
    }

    public function countConnections(): int
    {
        return count($this->connections);
    }
}