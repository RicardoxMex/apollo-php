<?php

namespace Apollo\Core\Console\Commands;

class RealtimeRestartCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:restart';
    protected string $description = 'Restart the realtime WebSocket server (Workerman)';

    public function handle(): int
    {
        $pid = $this->readPid();

        if ($pid) {
            $this->info("Deteniendo realtime server (pid={$pid})...");
            $this->stopProcess($pid);
        }

        $this->removePid();

        // Relanzar en background (cross-platform).
        $php = PHP_BINARY;
        $script = dirname(__DIR__, 3) . '/apollo';
        $extra = in_array('-d', $GLOBALS['argv'] ?? [], true) ? ' -d' : '';

        if (PHP_OS_FAMILY === 'Windows') {
            // start /B lanza en background sin nueva ventana; la salida va a nul.
            $cmd = sprintf('start /B "" %s %s realtime:start%s > nul 2>&1',
                escapeshellarg($php),
                escapeshellarg($script),
                $extra
            );
            pclose(popen($cmd, 'r'));
        } else {
            $cmd = sprintf('nohup %s %s realtime:start%s > /dev/null 2>&1 &',
                escapeshellarg($php),
                escapeshellarg($script),
                $extra
            );
            exec($cmd);
        }

        $this->info('Realtime server reiniciado (background).');

        return 0;
    }
}
