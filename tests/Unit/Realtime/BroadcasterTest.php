<?php

namespace Tests\Unit\Realtime;

use Apollo\Core\Realtime\Bus\LocalEventBus;
use Apollo\Core\Realtime\Events\Broadcaster;
use PHPUnit\Framework\TestCase;

class BroadcasterTest extends TestCase
{
    public function test_broadcast_publishes_protocol_payload(): void
    {
        $bus = new LocalEventBus();
        $received = [];

        $bus->subscribe('orders', function (array $payload) use (&$received) {
            $received[] = $payload;
        });

        (new Broadcaster($bus))->broadcast('orders', 'order.created', ['id' => 123]);

        $this->assertCount(1, $received);
        $this->assertSame([
            'type' => 'event',
            'channel' => 'orders',
            'event' => 'order.created',
            'data' => ['id' => 123],
        ], $received[0]);
    }

    public function test_fluent_to_emit(): void
    {
        $bus = new LocalEventBus();
        $received = [];

        $bus->subscribe('news', function (array $payload) use (&$received) {
            $received[] = $payload;
        });

        (new Broadcaster($bus))->to('news')->emit('news.published', ['title' => 'Hola']);

        $this->assertSame('news.published', $received[0]['event']);
        $this->assertSame('news', $received[0]['channel']);
    }

    public function test_emit_without_channel_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Broadcaster(new LocalEventBus()))->emit('sin.canales', []);
    }
}