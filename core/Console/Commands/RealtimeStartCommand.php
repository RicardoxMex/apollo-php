<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Realtime\Support\RealtimeManager;
use Apollo\Core\Realtime\WebSocket\WebSocketServer;

class RealtimeStartCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:start';
    protected string $description = 'Start the realtime WebSocket server (OpenSwoole)';

    public function handle(): int
    {
        $realtime = app(RealtimeManager::class);
        $server = new WebSocketServer($realtime);

        try {
            $server->checkExtension();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info('Starting realtime server...');
        $this->line('Driver: ' . $realtime->driver());
        $this->line('Host: ' . $realtime->config()->host() . ':' . $realtime->config()->port());

        if (function_exists('posix_getpid')) {
            $this->writePid(posix_getpid());
        } else {
            $this->writePid((int) getmypid());
        }

        $server->start();

        return 0;
    }
}