<?php

namespace Apollo\Core\Realtime\Events;

use Apollo\Core\Realtime\Contracts\EventBus;

/**
 * API sencilla para publicar eventos. Desacoplado de Redis: depende
 * únicamente de EventBus (y los canales locales del mismo proceso).
 */
class Broadcaster
{
    private EventBus $bus;
    private ?string $pendingChannel = null;

    public function __construct(EventBus $bus)
    {
        $this->bus = $bus;
    }

    /**
     * API fluida: ->to('orders')->emit('order.created', $data)
     */
    public function to(string $channel): self
    {
        $this->pendingChannel = $channel;

        return $this;
    }

    /**
     * Publicar un evento en un canal.
     */
    public function emit(string $event, array $data = [], ?string $channel = null): void
    {
        $target = $channel ?? $this->pendingChannel;

        if (!$target) {
            throw new \InvalidArgumentException('Broadcaster: canal no especificado (usa emit($event, $data, $channel) o ->to($channel))');
        }

        $this->broadcastPayload($target, [
            'type' => 'event',
            'channel' => $target,
            'event' => $event,
            'data' => $data,
        ]);
    }

    /**
     * API directa: broadcast('orders', 'order.created', [...])
     */
    public function broadcast(string $channel, string $event, array $data = []): void
    {
        $this->emit($event, $data, $channel);
    }

    private function broadcastPayload(string $channel, array $payload): void
    {
        $this->bus->publish($channel, $payload);
    }
}