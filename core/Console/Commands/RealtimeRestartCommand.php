<?php

namespace Apollo\Core\Console\Commands;

class RealtimeRestartCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:restart';
    protected string $description = 'Restart the realtime WebSocket server';

    public function handle(): int
    {
        $pid = $this->readPid();

        if ($pid) {
            $this->stopProcess($pid);
        }

        $this->removePid();

        // Relanzar en un proceso separado
        $php = PHP_BINARY;
        $script = dirname(__DIR__, 3) . '/apollo';

        exec(sprintf('%s %s realtime:start > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg($script)));

        $this->info('Realtime server reiniciado.');

        return 0;
    }
}