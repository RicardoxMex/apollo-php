<?php

namespace Tests\Feature\Realtime;

use Apollo\Core\Application;
use Apollo\Core\Realtime\Notifications\MySqlNotificationRepository;
use Apollo\Core\Realtime\Notifications\Notification;
use Apollo\Core\Realtime\Notifications\NotificationManager;
use Apollo\Core\Realtime\Support\RealtimeManager;
use PHPUnit\Framework\TestCase;

class OrderShippedNotification extends Notification
{
    private int $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function title(): string
    {
        return 'Pedido enviado';
    }

    public function message(): string
    {
        return "Pedido #{$this->orderId} enviado";
    }

    public function channels(): array
    {
        return ['database', 'realtime'];
    }

    public function data(): array
    {
        return ['order_id' => $this->orderId];
    }
}

class NotificationManagerTest extends TestCase
{
    public function test_send_dispatches_database_and_realtime(): void
    {
        $realtime = new RealtimeManager(['driver' => 'local', 'redis' => []], fn() => null);
        $received = [];

        $realtime->bus()->subscribe('private-user.7', function (array $payload) use (&$received) {
            $received[] = $payload;
        });

        $repo = $this->createMock(MySqlNotificationRepository::class);
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(fn($data) => $data['user_id'] === 7 && $data['type'] === 'OrderShippedNotification'))
            ->willReturn('notif_test');

        $manager = new NotificationManager($repo, $realtime);

        $sent = $manager->send(7, new OrderShippedNotification(123));

        $this->assertSame(['database', 'realtime'], $sent);
        $this->assertCount(1, $received);
        $this->assertSame('notification.received', $received[0]['event']);
        $this->assertSame(123, $received[0]['data']['notification']['data']['order_id']);
    }

    public function test_disabled_channel_is_skipped(): void
    {
        $realtime = new RealtimeManager(['driver' => 'local', 'redis' => []], fn() => null);
        $received = [];

        $realtime->bus()->subscribe('private-user.1', function () use (&$received) {
            $received[] = true;
        });

        $repo = $this->createMock(MySqlNotificationRepository::class);
        $manager = new NotificationManager($repo, $realtime);
        $manager->disable('realtime');

        $sent = $manager->send(1, new OrderShippedNotification(9));

        $this->assertSame(['database'], $sent);
        $this->assertCount(0, $received);
    }

    public function test_container_boots_manager_with_both_channels(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite no disponible');
        }

        // Bootear app mínima para app(NotificationManager::class)
        \Apollo\Core\Database\Connection\DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app = new Application(dirname(__DIR__, 3));
        $app->make('config');

        foreach ($app->make('config')->get('providers.core', []) as $providerClass) {
            if (class_exists($providerClass)) {
                $app->registerServiceProvider(new $providerClass($app));
            }
        }

        $manager = app(NotificationManager::class);

        $this->assertContains('database', $manager->channels());
        $this->assertContains('realtime', $manager->channels());
    }
}