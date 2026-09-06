<?php

namespace Tests\Unit\Realtime;

use Apollo\Core\Realtime\Bus\LocalEventBus;
use Apollo\Core\Realtime\Contracts\RedisConnection;
use Apollo\Core\Realtime\Support\RealtimeManager;
use PHPUnit\Framework\TestCase;

/**
 * Resolución de driver: auto → redis/local; redis explícito sin Redis → error.
 */
class RealtimeManagerTest extends TestCase
{
    private function stubFactory(bool $healthy): callable
    {
        return function () use ($healthy) {
            return new class($healthy) implements RedisConnection {
                public function __construct(private bool $healthy) {}

                public function connect(): void {}
                public function disconnect(): void {}
                public function connected(): bool { return $this->healthy; }
                public function ping(): bool { return $this->healthy; }
                public function publish(string $channel, string $message): int { return 1; }
                public function subscribeLoop(array $channels, callable $onMessage): void {}
            };
        };
    }

    public function test_auto_with_redis_available_resolves_redis(): void
    {
        $manager = new RealtimeManager(['driver' => 'auto', 'redis' => []], $this->stubFactory(true));

        $this->assertSame('redis', $manager->driver());
    }

    public function test_auto_without_redis_falls_back_to_local(): void
    {
        $manager = new RealtimeManager(['driver' => 'auto', 'redis' => []], $this->stubFactory(false));

        $this->assertSame('local', $manager->driver());
        $this->assertInstanceOf(LocalEventBus::class, $manager->bus());
    }

    public function test_redis_required_but_unavailable_throws_clear_error(): void
    {
        $manager = new RealtimeManager(['driver' => 'redis', 'redis' => []], $this->stubFactory(false));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REALTIME_DRIVER=redis');

        $manager->driver();
    }

    public function test_local_never_touches_redis(): void
    {
        $accessed = false;
        $factory = function () use (&$accessed) {
            $accessed = true;
            return new class implements RedisConnection {
                public function connect(): void {}
                public function disconnect(): void {}
                public function connected(): bool { return false; }
                public function ping(): bool { return false; }
                public function publish(string $channel, string $message): int { return 0; }
                public function subscribeLoop(array $channels, callable $onMessage): void {}
            };
        };

        $manager = new RealtimeManager(['driver' => 'local', 'redis' => []], $factory);

        $this->assertSame('local', $manager->driver());
        $this->assertFalse($accessed, 'local no debe intentar conectarse a Redis');
    }

    public function test_broadcast_and_to_emit_use_local_bus(): void
    {
        $manager = new RealtimeManager(['driver' => 'local', 'redis' => []], $this->stubFactory(false));
        $received = [];

        $manager->bus()->subscribe('orders', function (array $payload) use (&$received) {
            $received[] = $payload;
        });

        $manager->broadcast('orders', 'order.created', ['id' => 1]);
        $manager->to('orders')->emit('order.updated', ['id' => 2]);

        $this->assertCount(2, $received);
        $this->assertSame('order.created', $received[0]['event']);
        $this->assertSame('order.updated', $received[1]['event']);
    }
}