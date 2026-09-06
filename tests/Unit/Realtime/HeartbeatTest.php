<?php

namespace Tests\Unit\Realtime;

use Apollo\Core\Realtime\Support\RealtimeConfig;
use Apollo\Core\Realtime\WebSocket\Heartbeat;
use PHPUnit\Framework\TestCase;

class HeartbeatTest extends TestCase
{
    public function test_defaults_from_config(): void
    {
        $heartbeat = new Heartbeat(new RealtimeConfig([
            'heartbeat_interval' => 30,
            'connection_timeout' => 60,
        ]));

        $this->assertSame(30, $heartbeat->interval());
        $this->assertSame(60, $heartbeat->timeout());
    }

    public function test_connection_is_dead_after_timeout(): void
    {
        $heartbeat = new Heartbeat(new RealtimeConfig([
            'heartbeat_interval' => 10,
            'connection_timeout' => 30,
        ]));

        $now = 1_000_000;

        $this->assertFalse($heartbeat->isDead($now - 10, $now));
        $this->assertTrue($heartbeat->isDead($now - 40, $now));
    }
}