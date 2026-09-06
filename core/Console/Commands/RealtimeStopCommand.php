<?php

namespace Apollo\Core\Console\Commands;

class RealtimeStopCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:stop';
    protected string $description = 'Stop the realtime WebSocket server';

    public function handle(): int
    {
        $pid = $this->readPid();

        if (!$pid) {
            $this->warn('Realtime server no está corriendo (sin pid file).');
            return 0;
        }

        $this->stopProcess($pid);
        $this->removePid();
        $this->info('Realtime server detenido.');

        return 0;
    }
}