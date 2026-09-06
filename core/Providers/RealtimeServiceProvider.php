<?php

namespace Apollo\Core\Providers;

use Apollo\Core\Container\ServiceProvider;
use Apollo\Core\Realtime\Notifications\MySqlNotificationRepository;
use Apollo\Core\Realtime\Notifications\NotificationManager;
use Apollo\Core\Realtime\Support\RealtimeManager;

/**
 * Módulo realtime: bindings inertes hasta que se usan (opcional por proyecto).
 */
class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(RealtimeManager::class, function ($app) {
            return new RealtimeManager(
                config('realtime', [])
            );
        });

        // Alias 'realtime' → manager (para helpers/facades)
        $this->container->alias(RealtimeManager::class, 'realtime');

        $this->container->singleton(NotificationManager::class, function ($app) {
            $manager = new NotificationManager(
                config('realtime.notifications.database', true) ? new MySqlNotificationRepository() : null,
                $app->make(RealtimeManager::class)
            );

            return $manager;
        });

        // Alias 'realtime.notifications' → manager
        $this->container->alias(NotificationManager::class, 'realtime.notifications');
    }
}