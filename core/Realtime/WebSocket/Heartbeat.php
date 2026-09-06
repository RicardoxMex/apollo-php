<?php

namespace Apollo\Core\Realtime\WebSocket;

use Apollo\Core\Realtime\Support\RealtimeConfig;

/**
 * Heartbeat: ping/pong automático y detección de conexiones muertas.
 *
 * Lógica pura (testeable sin OpenSwoole): el servidor llama tick() en un
 * timer y sweep() periódicamente.
 */
class Heartbeat
{
    private int $interval;
    private int $timeout;

    public function __construct(RealtimeConfig $config)
    {
        $this->interval = $config->heartbeatInterval();
        $this->timeout = $config->connectionTimeout();
    }

    public function interval(): int
    {
        return $this->interval;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Marcar la conexión como viva (pong recibido / frame recibido).
     */
    public function touch(int $lastSeen): void
    {
        // (el valor se guarda en la Connection vía touch())
        unset($lastSeen);
    }

    /**
     * Conexiones que no han enviado nada en timeout segundos → muertas.
     */
    public function isDead(int $lastSeen, ?int $now = null): bool
    {
        $now = $now ?? time();

        return ($now - $lastSeen) > $this->timeout;
    }
}