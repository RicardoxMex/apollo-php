<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Application;
use Apollo\Core\Realtime\Support\RealtimeConfig;
use Apollo\Core\Realtime\WebSocket\WebSocketServer;

class RealtimeStartCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:start';
    protected string $description = 'Start the realtime WebSocket server (Workerman)';

    public function handle(): int
    {
        if ($this->isRunning()) {
            $this->warn('Realtime server ya está corriendo (pid=' . $this->readPid() . ').');
            return 0;
        }

        $basePath = dirname(__DIR__, 3);

        // Carga .env (mismo patrón que el binario apollo)
        if (file_exists($basePath . '/.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable($basePath);
            $dotenv->load();
        }

        $config = is_file($basePath . '/config/realtime.php')
            ? require $basePath . '/config/realtime.php'
            : [];

        if (!($config['enabled'] ?? true)) {
            $this->error('WebSocket deshabilitado (WEBSOCKET_ENABLED=false).');
            return 1;
        }

        // Detecta -d (daemonize) en argv global (php apollo realtime:start -d)
        $daemon = PHP_OS_FAMILY !== 'Windows' && in_array('-d', $GLOBALS['argv'] ?? [], true);

        if ($daemon) {
            \Workerman\Worker::$daemonize = true;
        }

        \Workerman\Worker::$pidFile = $this->pidFile();
        \Workerman\Worker::$logFile = $basePath . '/runtime/workerman.log';

        // En modo no-daemon, escribimos el pid manualmente para que stop/status funcionen.
        // En daemon mode, Workerman sobreescribirá con el pid del proceso daemonizado.
        if (!$daemon) {
            $this->writePid(function_exists('posix_getpid') ? posix_getpid() : (int) getmypid());
        }

        $this->info('Starting realtime server (Workerman)...');
        $realtimeConfig = new RealtimeConfig($config);
        $this->line('Driver:  ' . $realtimeConfig->host() . ':' . $realtimeConfig->port());
        $this->line('Scheme:  ' . ($realtimeConfig->sslEnabled() ? 'wss' : 'ws'));
        $this->line('Poll:    ' . $realtimeConfig->pollInterval() . 's');
        $this->line('Beat:    ' . $realtimeConfig->heartbeatInterval() . 's');

        $server = new WebSocketServer($basePath, $realtimeConfig);
        $server->start();

        return 0;
    }
}
