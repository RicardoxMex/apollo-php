<?php

namespace Apollo\Core\Realtime;

use Apollo\Core\Realtime\Support\RealtimeManager;

/**
 * Fachada estática del módulo realtime.
 *
 *   Realtime::broadcast('orders', 'order.created', ['id' => 123]);
 *   Realtime::to('orders')->emit('order.created', $data);
 *   Realtime::driver(); // 'redis' | 'local'
 */
class Realtime
{
    public static function manager(): RealtimeManager
    {
        return app(RealtimeManager::class);
    }

    public static function broadcast(string $channel, string $event, array $data = []): void
    {
        self::manager()->broadcast($channel, $event, $data);
    }

    public static function to(string $channel)
    {
        return self::manager()->to($channel);
    }

    public static function driver(): string
    {
        return self::manager()->driver();
    }

    public static function config()
    {
        return self::manager()->config();
    }
}