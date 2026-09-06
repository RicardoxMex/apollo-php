<?php

namespace Apollo\Core\Realtime\Bus;

use Apollo\Core\Realtime\Contracts\EventBus;
use Apollo\Core\Realtime\Contracts\RedisConnection;

/**
 * EventBus sobre Redis Pub/Sub: sincroniza múltiples instancias del
 * servidor WebSocket. Requiere Redis disponible.
 */
class RedisEventBus implements EventBus
{
    private RedisConnection $redis;
    private array $handlers = [];

    public function __construct(RedisConnection $redis)
    {
        $this->redis = $redis;
    }

    public function publish(string $topic, array $payload): void
    {
        $this->redis->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
     * Bucle de consumo (bloqueante) usado en el servidor WebSocket:
     * entrega cada mensaje a los handlers registrados del tópico.
     */
    public function consume(array $topics, ?callable $onError = null): void
    {
        $handler = $this->handlers;

        $this->redis->subscribeLoop($topics, function (string $channel, string $payload) use ($handler) {
            $data = json_decode($payload, true);

            if (!is_array($data)) {
                return;
            }

            foreach ($handler[$channel] ?? [] as $callback) {
                $callback($data, $channel);
            }
        });
    }

    /**
     * Consumo por patrón (PSUBSCRIBE '*' = todos los canales). Pensado para
     * un worker dedicado que reenvía los eventos a los clientes conectados.
     */
    public function consumePattern(string $pattern, callable $onMessage): void
    {
        $this->redis->psubscribeLoop([$pattern], function (string $channel, string $payload) use ($onMessage) {
            $data = json_decode($payload, true);

            if (!is_array($data)) {
                return;
            }

            $onMessage($data, $channel);
        });
    }

    public function healthy(): bool
    {
        return $this->redis->ping();
    }
}