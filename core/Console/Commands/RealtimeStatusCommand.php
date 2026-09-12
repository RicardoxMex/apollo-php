<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Realtime\Support\RealtimeManager;

class RealtimeStatusCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:status';
    protected string $description = 'Show realtime server status (Workerman)';

    public function handle(): int
    {
        $realtime = app(RealtimeManager::class);

        $running = $this->isRunning();
        $driver = $realtime->driver();
        $pid = $this->readPid();

        $this->info('Realtime System');
        $this->line('------------------------------');
        $this->line('Status:    ' . ($running ? 'running' : 'stopped'));
        $this->line('Driver:    ' . $driver);
        $this->line('Host:      ' . $realtime->config()->host());
        $this->line('Port:      ' . $realtime->config()->port());
        $this->line('Scheme:    ' . ($realtime->config()->sslEnabled() ? 'wss' : 'ws'));
        $this->line('PID:       ' . ($pid ?: '-'));
        $this->line('Poll:      ' . $realtime->config()->pollInterval() . 's');
        $this->line('Heartbeat: ' . $realtime->config()->heartbeatInterval() . 's (timeout ' . $realtime->config()->connectionTimeout() . 's)');

        return 0;
    }
}
