<?php

namespace Apollo\Core\Realtime\Events;

use Apollo\Core\Realtime\Contracts\EventBus;
use Apollo\Core\Realtime\Contracts\RealtimeEvent;

/**
 * Despacha objetos RealtimeEvent (y payloads) al EventBus.
 */
class EventDispatcher
{
    private EventBus $bus;

    public function __construct(EventBus $bus)
    {
        $this->bus = $bus;
    }

    public function dispatch(RealtimeEvent $event): void
    {
        $this->bus->publish($event->channel(), [
            'type' => 'event',
            'channel' => $event->channel(),
            'event' => $event->name(),
            'data' => $event->data(),
        ]);
    }
}