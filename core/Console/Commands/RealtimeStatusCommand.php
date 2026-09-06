<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Realtime\Support\RealtimeManager;

class RealtimeStatusCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:status';
    protected string $description = 'Show realtime server status';

    public function handle(): int
    {
        $realtime = app(RealtimeManager::class);

        $running = $this->isRunning();
        $driver = $realtime->driver();

        $this->info('Realtime System');
        $this->line('------------------------------');
        $this->line('Status:    ' . ($running ? 'running' : 'stopped'));
        $this->line('Driver:    ' . $driver);
        $this->line('Host:      ' . $realtime->config()->host());
        $this->line('Port:      ' . $realtime->config()->port());
        $this->line('PID:       ' . ($this->readPid() ?: '-'));
        $this->line('Heartbeat: ' . $realtime->config()->heartbeatInterval() . 's (timeout ' . $realtime->config()->connectionTimeout() . 's)');

        return 0;
    }
}