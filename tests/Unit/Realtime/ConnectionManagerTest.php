<?php

namespace Tests\Unit\Realtime;

use Apollo\Core\Realtime\Connections\ConnectionManager;
use PHPUnit\Framework\TestCase;

class ConnectionManagerTest extends TestCase
{
    public function test_connect_returns_unique_connections(): void
    {
        $manager = new ConnectionManager();

        $a = $manager->connect();
        $b = $manager->connect();

        $this->assertNotSame($a->id(), $b->id());
        $this->assertSame(2, $manager->countConnections());
    }

    public function test_multiple_connections_for_same_user(): void
    {
        $manager = new ConnectionManager();
        $c1 = $manager->connect(25);
        $c2 = $manager->connect(25);
        $c3 = $manager->connect(26);

        $this->assertCount(2, $manager->connectionsForUser(25));
        $this->assertTrue($manager->hasUser(25));
        $this->assertFalse($manager->hasUser(99));
    }

    public function test_disconnect_removes_connection_and_channel_subs(): void
    {
        $manager = new ConnectionManager();
        $connection = $manager->connect(25);

        $connection->subscribe('orders');
        $this->assertTrue($manager->channels()->hasSubscriber('orders', $connection));

        $manager->disconnect($connection->id());

        $this->assertFalse($manager->hasConnection($connection->id()));
        $this->assertFalse($manager->hasUser(25));
        $this->assertNull($manager->channels()->get('orders'));
    }

    public function test_send_and_broadcast(): void
    {
        $manager = new ConnectionManager();
        $a = $manager->connect(1);
        $b = $manager->connect(2);
        $receivedA = [];
        $receivedB = [];

        $a->attachResource(function (array $p) use (&$receivedA) { $receivedA[] = $p; });
        $b->attachResource(function (array $p) use (&$receivedB) { $receivedB[] = $p; });

        $manager->send($a->id(), ['type' => 'direct']);
        $manager->broadcast(['type' => 'global']);

        // A: direct + global · B: solo global
        $this->assertCount(2, $receivedA);
        $this->assertCount(1, $receivedB);
        $this->assertSame('global', $receivedB[0]['type']);
    }

    public function test_broadcast_ignores_excepted_connections(): void
    {
        $manager = new ConnectionManager();
        $a = $manager->connect(1);
        $b = $manager->connect(2);
        $receivedA = [];
        $receivedB = [];

        $a->attachResource(function (array $p) use (&$receivedA) { $receivedA[] = $p; });
        $b->attachResource(function (array $p) use (&$receivedB) { $receivedB[] = $p; });

        $manager->broadcast(['type' => 'x'], [$a->id()]);

        $this->assertCount(0, $receivedA);
        $this->assertCount(1, $receivedB);
    }

    public function test_max_connections_overflow(): void
    {
        $manager = new ConnectionManager(null, 2);

        $manager->connect();
        $manager->connect();

        $this->expectException(\OverflowException::class);
        $manager->connect();
    }
}