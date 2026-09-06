<?php

namespace Tests\Feature\Realtime;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Realtime\Notifications\MySqlNotificationRepository;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Repositorio de notificaciones sobre SQLite :memory: (esquema de la 009).
 */
class NotificationRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private MySqlNotificationRepository $repo;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite requerido para la prueba de persistencia');
        }

        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // BD fresca por clase
        DatabaseManager::disconnect();

        self::$pdo = DatabaseManager::getConnection();

        self::$pdo->exec(<<<'SQL'
CREATE TABLE `notifications` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `user_id` INTEGER NOT NULL,
    `type` VARCHAR(100) NOT NULL,
    `title` VARCHAR(255) NULL,
    `message` TEXT NULL,
    `data` TEXT NULL,
    `read_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }

    protected function setUp(): void
    {
        $this->repo = new MySqlNotificationRepository(self::$pdo);
    }

    public function test_create_find_for_user_mark_read_delete(): void
    {
        $id = $this->repo->create([
            'user_id' => 5,
            'type' => 'OrderShipped',
            'title' => 'Pedido enviado',
            'message' => 'Tu pedido #1 está en camino',
            'data' => ['order_id' => 1],
        ]);

        $this->assertStringStartsWith('notif_', $id);

        $found = $this->repo->find($id);
        $this->assertNotNull($found);
        $this->assertSame('OrderShipped', $found['type']);
        $this->assertSame(['order_id' => 1], $found['data']);

        $list = $this->repo->forUser(5);
        $this->assertCount(1, $list);

        $read = $this->repo->markAsRead($id);
        $this->assertTrue($read);

        $this->assertCount(0, $this->repo->forUser(5, ['unread' => true]));
        $this->assertCount(1, $this->repo->forUser(5));

        $deleted = $this->repo->delete($id);
        $this->assertTrue($deleted);
        $this->assertNull($this->repo->find($id));
    }

    public function test_for_user_isolates_users(): void
    {
        $this->repo->create(['user_id' => 1, 'type' => 'A', 'title' => 'a']);
        $this->repo->create(['user_id' => 2, 'type' => 'B', 'title' => 'b']);

        $this->assertCount(1, $this->repo->forUser(1));
        $this->assertCount(1, $this->repo->forUser(2));
        $this->assertCount(0, $this->repo->forUser(3));
    }
}