<?php

namespace Apollo\Core\Console\Commands;

class RealtimeStopCommand extends RealtimeCommand
{
    protected string $signature = 'realtime:stop';
    protected string $description = 'Stop the realtime WebSocket server (Workerman)';

    public function handle(): int
    {
        $pid = $this->readPid();

        if (!$pid) {
            $this->warn('Realtime server no está corriendo (sin pid file).');
            return 0;
        }

        $this->stopProcess($pid);
        $this->removePid();

        // Limpia el high-water-mark de notificaciones entregadas (estado por instancia).
        $deliveryFile = dirname($this->pidFile()) . '/realtime-delivery.json';
        if (is_file($deliveryFile)) {
            @unlink($deliveryFile);
        }

        $this->info('Realtime server detenido.');

        return 0;
    }
}