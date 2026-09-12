<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Realtime\Support\RealtimeManager;

/**
 * realtime:test — health check del sistema realtime (Workerman).
 */
class RealtimeTestCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:test';
    protected string $description = 'Check realtime system (PHP, Workerman, Redis, DB, config)';

    public function handle(): int
    {
        $realtime = app(RealtimeManager::class);

        $okPhp = version_compare(PHP_VERSION, '8.3', '>=');
        $okWorkerman = class_exists(\Workerman\Worker::class);
        $workermanVersion = $okWorkerman && defined(\Workerman\Worker::class . '::VERSION')
            ? \Workerman\Worker::VERSION
            : 'n/a';
        $driver = 'local';
        $redisHealthy = false;
        $okRedis = true;

        try {
            $driver = $realtime->driver();
            $redisHealthy = $driver === 'redis';
            $okRedis = $driver !== 'redis';
        } catch (\Throwable $e) {
            $okRedis = false;
        }

        $dbOk = true;
        $dbErr = null;
        try {
            \Apollo\Core\Database\Connection\DatabaseManager::getConnection();
        } catch (\Throwable $e) {
            $dbOk = false;
            $dbErr = $e->getMessage();
        }

        $secretOk = $realtime->config()->appSecret() !== '';
        $poll = $realtime->config()->pollInterval();
        $sslOk = !$realtime->config()->sslEnabled() ||
            ($realtime->config()->sslLocalCert() && $realtime->config()->sslLocalKey());

        $this->info('Realtime System');
        $this->line('----------------');
        $this->line('PHP            ' . ($okPhp ? '✓' : '✗') . '  ' . PHP_VERSION);
        $this->line('Workerman      ' . ($okWorkerman ? '✓' : '✗') . '  v' . $workermanVersion . '  (paquete Composer)');
        $this->line('Redis          ' . ($redisHealthy ? '✓' : ($okRedis ? '·' : '✗')) . '  '
            . ($realtime->config()->redisConfig()['host'] ?? '') . ':' . ($realtime->config()->redisConfig()['port'] ?? ''));
        $this->line('Database       ' . ($dbOk ? '✓' : '✗') . '  ' . ($dbOk ? 'conectable' : ($dbErr ?? 'no disponible')));
        $this->line('App secret     ' . ($secretOk ? '✓' : '·') . '  ' . ($secretOk ? 'configurado' : 'vacío (canales privados deshabilitados)'));
        $this->line('SSL            ' . ($sslOk ? '✓' : '✗') . '  ' . ($realtime->config()->sslEnabled() ? 'WSS habilitado' : 'WS (terminar TLS en Nginx)'));
        $this->line('Poll interval  ' . $poll . 's');
        $this->line('');
        $this->line('Driver:  ' . $driver);
        $this->line('Status:  ' . ($okPhp && $okWorkerman && $dbOk ? 'ready' : 'degraded'));

        if (!$okWorkerman) {
            $this->warn('Workerman no instalado. Ejecuta: composer require workerman/workerman');
        }

        return 0;
    }
}
