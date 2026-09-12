<?php

namespace Apollo\Core\Realtime\Support;

use Apollo\Core\Realtime\Bus\LocalEventBus;
use Apollo\Core\Realtime\Bus\RedisEventBus;
use Apollo\Core\Realtime\Contracts\EventBus;
use Apollo\Core\Realtime\Contracts\RedisConnection;
use Apollo\Core\Realtime\Events\Broadcaster;

/**
 * RealtimeManager — resuelve el driver activo (auto | redis | local) y
 * expone el bus, el broadcaster y helpers de alto nivel.
 *
 * auto: health check a Redis (ping); si falla → LocalEventBus.
 */
class RealtimeManager
{
    private RealtimeConfig $config;

    /** @var callable(): RedisConnection */
    private $redisFactory;

    private ?EventBus $bus = null;
    private ?string $resolvedDriver = null;

    /**
     * Modo del proceso:
     *  - true  → bus en proceso (LocalEventBus/RedisEventBus). Tests, CLI, server-side.
     *  - false → bus cross-process (HttpEventBus para local, RedisEventBus para redis). App-side.
     */
    private bool $inProcess;

    public function __construct(array $config, ?callable $redisFactory = null, bool $inProcess = true)
    {
        $this->config = new RealtimeConfig($config);
        $this->inProcess = $inProcess;

        // Default: RedisClient con la config de redis
        $this->redisFactory = $redisFactory
            ?? fn() => new RedisClient($this->config->redisConfig());
    }

    /**
     * Driver efectivo (local | redis), sin construir el bus aún.
     */
    public function driver(): string
    {
        if ($this->resolvedDriver !== null) {
            return $this->resolvedDriver;
        }

        $requested = $this->config->driver();

        if ($requested === 'local') {
            return $this->resolvedDriver = 'local';
        }

        $redisHealthy = ($this->redisFactory)()->ping();

        if ($requested === 'redis') {
            if (!$redisHealthy) {
                throw new \RuntimeException(
                    'REALTIME_DRIVER=redis está configurado explícitamente, pero Redis no está disponible. '
                    . 'Revisa REDIS_HOST/REDIS_PORT o usa REALTIME_DRIVER=auto/local.'
                );
            }
            return $this->resolvedDriver = 'redis';
        }

        // auto
        return $this->resolvedDriver = $redisHealthy ? 'redis' : 'local';
    }

    public function bus(): EventBus
    {
        if ($this->bus !== null) {
            return $this->bus;
        }

        $this->resolvedDriver = null;
        $driver = $this->driver();

        if ($driver === 'redis') {
            $this->bus = new RedisEventBus(($this->redisFactory)());
        } elseif ($this->inProcess) {
            $this->bus = new LocalEventBus();
        } else {
            // App-side (no in-process): el bus local no puede alcanzar al servidor
            // desde otro proceso. La entrega a usuarios se hace por polling del
            // servidor sobre la tabla `notifications` (ver design D4-revisado).
            // Para canales public/private/presence cross-process, usar Redis.
            $this->bus = new LocalEventBus();
        }

        return $this->bus;
    }

    public function broadcaster(): Broadcaster
    {
        return new Broadcaster($this->bus());
    }

    /**
     * Publicar un evento en un canal (API de alto nivel).
     */
    public function broadcast(string $channel, string $event, array $data = []): void
    {
        $this->broadcaster()->broadcast($channel, $event, $data);
    }

    /**
     * API fluida: Realtime::to('orders')->emit('order.created', $data).
     */
    public function to(string $channel): Broadcaster
    {
        return $this->broadcaster()->to($channel);
    }

    public function config(): RealtimeConfig
    {
        return $this->config;
    }

    public function isInProcess(): bool
    {
        return $this->inProcess;
    }
}