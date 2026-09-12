<?php

namespace Tests\Unit\Realtime;

use Apollo\Core\Realtime\Channels\ChannelManager;
use Apollo\Core\Realtime\Connections\ConnectionManager;
use PHPUnit\Framework\TestCase;

class ConnectionManagerSendToUserTest extends TestCase
{
    public function test_send_to_user_delivers_to_all_user_connections(): void
    {
        $manager = new ConnectionManager(new ChannelManager());

        $a = $manager->connect(7);
        $b = $manager->connect(7);
        $c = $manager->connect(8);

        $receivedA = [];
        $receivedB = [];
        $receivedC = [];

        $a->attachResource(function (array $p) use (&$receivedA) { $receivedA[] = $p; });
        $b->attachResource(function (array $p) use (&$receivedB) { $receivedB[] = $p; });
        $c->attachResource(function (array $p) use (&$receivedC) { $receivedC[] = $p; });

        $delivered = $manager->sendToUser(7, ['type' => 'notification', 'data' => ['n' => 1]]);

        $this->assertSame(2, $delivered, 'debe entregar a las 2 conexiones del user 7');
        $this->assertCount(1, $receivedA);
        $this->assertCount(1, $receivedB);
        $this->assertCount(0, $receivedC, 'la conexión del user 8 NO debe recibir');

        $this->assertSame('notification', $receivedA[0]['type']);
    }

    public function test_send_to_user_returns_zero_when_user_has_no_connections(): void
    {
        $manager = new ConnectionManager(new ChannelManager());
        $delivered = $manager->sendToUser(99, ['type' => 'notification']);

        $this->assertSame(0, $delivered);
    }

    public function test_sweep_removes_idle_connections(): void
    {
        $manager = new ConnectionManager(new ChannelManager(), 100);
        $a = $manager->connect(1);
        $b = $manager->connect(2);

        $this->assertSame(2, $manager->countConnections());

        // Forzar el lastSeen de $a a algo muy antiguo
        $ref = new \ReflectionClass($a);
        $prop = $ref->getProperty('lastSeen');
        $prop->setAccessible(true);
        $prop->setValue($a, time() - 1000);

        $removed = $manager->sweep(60);
        $this->assertSame(1, $removed);
        $this->assertSame(1, $manager->countConnections());
    }

    public function test_get_user_ids(): void
    {
        $manager = new ConnectionManager(new ChannelManager());
        $manager->connect(1);
        $manager->connect(1);
        $manager->connect(2);

        $ids = $manager->getUserIds();
        sort($ids);

        $this->assertSame([1, 2], $ids);
    }
}
