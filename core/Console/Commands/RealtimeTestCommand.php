<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Realtime\Support\RealtimeManager;

/**
 * realtime:test — health check del sistema realtime.
 */
class RealtimeTestCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:test';
    protected string $description = 'Check realtime system (PHP, OpenSwoole, Redis, DB, config)';

    public function handle(): int
    {
        $realtime = app(RealtimeManager::class);

        $okPhp = version_compare(PHP_VERSION, '8.1', '>=');
        $okSwoole = extension_loaded('openswoole');
        $driver = 'local';
        $okRedis = true;
        $redisHealthy = false;

        try {
            $driver = $realtime->driver();
            $redisHealthy = $driver === 'redis';
            $okRedis = $driver !== 'redis'; // si resuelve a local, Redis no está (ok en auto)
        } catch (\Throwable $e) {
            $okRedis = false;
        }

        $dbOk = true;
        try {
            \Apollo\Core\Database\Connection\DatabaseManager::getConnection();
        } catch (\Throwable $e) {
            $dbOk = false;
        }

        $secretOk = $realtime->config()->appSecret() !== '';

        $this->info('Realtime System');
        $this->line('----------------');
        $this->line('PHP            ' . ($okPhp ? '✓' : '✗') . '  ' . PHP_VERSION);
        $this->line('OpenSwoole     ' . ($okSwoole ? '✓' : '✗') . '  ' . ($okSwoole ? 'available' : 'extension required para realtime:start'));
        $this->line('Redis          ' . ($redisHealthy ? '✓' : '✗') . '  ' . ($realtime->config()->redisConfig()['host'] ?? '') . ':' . ($realtime->config()->redisConfig()['port'] ?? ''));
        $this->line('MySQL          ' . ($dbOk ? '✓' : '✗'));
        $this->line('App secret     ' . ($secretOk ? '✓' : '✗') . '  (necesario para canales privados)');
        $this->line('');
        $this->line('Driver: ' . $driver);
        $this->line('Status: ' . ($okPhp && $okSwoole && $dbOk ? 'ready' : 'degraded'));

        return 0;
    }
}