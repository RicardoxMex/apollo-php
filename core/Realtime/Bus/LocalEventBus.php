<?php

namespace Apollo\Core\Realtime\Bus;

use Apollo\Core\Realtime\Contracts\EventBus;

/**
 * EventBus en memoria del proceso — sin Redis.
 *
 * Pensado para desarrollo y servidores únicos. NO permite comunicación
 * entre múltiples instancias del servidor WebSocket.
 */
class LocalEventBus implements EventBus
{
    private array $handlers = [];

    /**
     * Hook global (usado por el servidor WebSocket para reenviar todo
     * tópico publicado en el mismo proceso a los clientes conectados).
     */
    private $onPublish = null;

    public function publish(string $topic, array $payload): void
    {
        if ($this->onPublish) {
            ($this->onPublish)($topic, $payload);
        }

        foreach ($this->handlers[$topic] ?? [] as $handler) {
            $handler($payload, $topic);
        }
    }

    public function subscribe(string $topic, callable $handler): void
    {
        $this->handlers[$topic][] = $handler;
    }

    public function unsubscribe(string $topic): void
    {
        unset($this->handlers[$topic]);
    }

    /**
     * Enlaces todos los tópicos publicados en este proceso.
     */
    public function onPublish(callable $callback): void
    {
        $this->onPublish = $callback;
    }
}