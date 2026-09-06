<?php

namespace Tests\Unit\Realtime;

use Apollo\Core\Realtime\Auth\ChannelAuthenticator;
use Apollo\Core\Realtime\Channels\ChannelManager;
use Apollo\Core\Realtime\Connections\ConnectionManager;
use Apollo\Core\Realtime\Support\RealtimeConfig;
use Apollo\Core\Realtime\WebSocket\MessageHandler;
use PHPUnit\Framework\TestCase;

class MessageHandlerTest extends TestCase
{
    private function handler(array $config = []): MessageHandler
    {
        $full = array_merge(['app' => ['key' => 'k', 'secret' => 's3cret']], $config);
        $configObj = new RealtimeConfig($full);

        $connections = new ConnectionManager(new ChannelManager());

        return new MessageHandler($connections, new ChannelAuthenticator($configObj), $configObj);
    }

    private function withHandler(MessageHandler $handler, callable $fn): void
    {
        $ref = new \ReflectionClass($handler);
        $prop = $ref->getProperty('connections');
        $connections = $prop->getValue($handler);

        $connection = $connections->connect(25);

        $fn($handler, $connection, $connections);
    }

    public function test_subscribe_public_channel(): void
    {
        $handler = $this->handler();

        $this->withHandler($handler, function ($handler, $connection) {
            $results = $handler->handle($connection, ['type' => 'subscribe', 'channel' => 'orders']);

            $this->assertSame('subscribed', $results[0]['payload']['type']);
            $this->assertSame('orders', $results[0]['payload']['channel']);
            $this->assertContains('orders', $connection->channels());
        });
    }

    public function test_private_channel_rejects_without_ticket(): void
    {
        $handler = $this->handler();

        $this->withHandler($handler, function ($handler, $connection) {
            $results = $handler->handle($connection, [
                'type' => 'subscribe',
                'channel' => 'private-user.25',
                'user_id' => 25,
            ]);

            $this->assertSame('error', $results[0]['payload']['type']);
            $this->assertSame('CHANNEL_UNAUTHORIZED', $results[0]['payload']['code']);
        });
    }

    public function test_private_channel_accepts_valid_ticket(): void
    {
        $handler = $this->handler();

        $this->withHandler($handler, function ($handler, $connection) {
            $authenticator = new ChannelAuthenticator($this->config('s3cret'));
            $ticket = $authenticator->sign('private-user.25', 25);

            $results = $handler->handle($connection, [
                'type' => 'subscribe',
                'channel' => 'private-user.25',
                'user_id' => 25,
                'auth' => $ticket,
            ]);

            $this->assertSame('subscribed', $results[0]['payload']['type']);
        });
    }

    public function test_ping_returns_pong(): void
    {
        $handler = $this->handler();

        $this->withHandler($handler, function ($handler, $connection) {
            $results = $handler->handle($connection, ['type' => 'ping']);

            $this->assertSame('pong', $results[0]['payload']['type']);
        });
    }

    public function test_presence_subscribe_emits_member_joined(): void
    {
        $handler = $this->handler();

        $this->withHandler($handler, function ($handler, $connection) {
            $ticket = (new ChannelAuthenticator($this->config('s3cret')))->sign('presence-chat.1', 42);

            $results = $handler->handle($connection, [
                'type' => 'subscribe',
                'channel' => 'presence-chat.1',
                'user_id' => 42,
                'auth' => $ticket,
                'user_info' => ['name' => 'Ana'],
            ]);

            $this->assertSame('subscribed', $results[0]['payload']['type']);
            $this->assertArrayHasKey('members', $results[0]['payload']);

            $broadcast = $results[1]['broadcast'] ?? null;
            $this->assertNotNull($broadcast);
            $this->assertSame('member.joined', $broadcast['payload']['event']);
            $this->assertSame('Ana', $broadcast['payload']['data']['member']['info']['name']);
        });
    }

    private function config(string $secret): RealtimeConfig
    {
        return new RealtimeConfig(['app' => ['key' => 'k', 'secret' => $secret]]);
    }
}