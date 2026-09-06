<?php

namespace Tests\Unit\Realtime;

use Apollo\Core\Realtime\Channels\ChannelManager;
use Apollo\Core\Realtime\Channels\PresenceChannel;
use Apollo\Core\Realtime\Connections\ConnectionManager;
use PHPUnit\Framework\TestCase;

class ChannelManagerTest extends TestCase
{
    public function test_channel_type_detection(): void
    {
        $this->assertSame(ChannelManager::PUBLIC, ChannelManager::channelType('orders'));
        $this->assertSame(ChannelManager::PRIVATE, ChannelManager::channelType('private-user.25'));
        $this->assertSame(ChannelManager::PRESENCE, ChannelManager::channelType('presence-chat.1'));
    }

    public function test_subscribe_unsubscribe_and_has_subscriber(): void
    {
        $channels = new ChannelManager();
        $connections = new ConnectionManager($channels);
        $connA = $connections->connect(25);
        $connB = $connections->connect(25);

        $channels->subscribe('orders', $connA);
        $channels->subscribe('orders', $connB);

        $this->assertTrue($channels->hasSubscriber('orders', $connA));
        $this->assertCount(2, $channels->getSubscribers('orders'));

        $channels->unsubscribe('orders', $connA);

        $this->assertFalse($channels->hasSubscriber('orders', $connA));
        $this->assertCount(1, $channels->getSubscribers('orders'));
    }

    public function test_channel_broadcast_sends_to_subscribers(): void
    {
        $channels = new ChannelManager();
        $connections = new ConnectionManager($channels);
        $connA = $connections->connect(25);
        $connB = $connections->connect(26);
        $receivedA = [];
        $receivedB = [];

        $connA->attachResource(function (array $p) use (&$receivedA) { $receivedA[] = $p; });
        $connB->attachResource(function (array $p) use (&$receivedB) { $receivedB[] = $p; });

        $channels->subscribe('orders', $connA);
        $channels->subscribe('orders', $connB);

        $channels->broadcast('orders', ['event' => 'order.created']);

        $this->assertCount(1, $receivedA);
        $this->assertCount(1, $receivedB);
    }

    public function test_empty_channel_is_cleaned_up(): void
    {
        $channels = new ChannelManager();
        $connections = new ConnectionManager($channels);
        $conn = $connections->connect(1);

        $channels->subscribe('news', $conn);
        $this->assertNotNull($channels->get('news'));

        $channels->unsubscribe('news', $conn);
        $this->assertNull($channels->get('news'));
    }

    public function test_presence_members_join_and_leave(): void
    {
        $channels = new ChannelManager();
        $connections = new ConnectionManager($channels);
        $conn = $connections->connect(10);

        $channel = $channels->getOrCreate('presence-team.10');

        $this->assertInstanceOf(PresenceChannel::class, $channel);

        $result = $channel->addMember($conn->id(), 10, ['name' => 'Ana']);
        $this->assertSame('Ana', $result['member']['info']['name']);
        $this->assertCount(1, $channel->getMembers());

        $left = $channel->removeMember($conn->id());
        $this->assertNotNull($left);
        $this->assertCount(0, $channel->getMembers());
    }
}