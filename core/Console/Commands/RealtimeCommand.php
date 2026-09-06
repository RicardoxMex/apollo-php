<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

/**
 * Base de los comandos realtime (pid file en runtime/).
 */
abstract class RealtimeCommand extends Command
{
    protected function pidFile(): string
    {
        return dirname(__DIR__, 3) . '/runtime/realtime.pid';
    }

    protected function writePid(int $pid): void
    {
        $dir = dirname($this->pidFile());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->pidFile(), (string) $pid);
    }

    protected function readPid(): ?int
    {
        $file = $this->pidFile();

        return file_exists($file) ? (int) file_get_contents($file) : null;
    }

    protected function removePid(): void
    {
        if (file_exists($this->pidFile())) {
            unlink($this->pidFile());
        }
    }

    protected function isRunning(): bool
    {
        $pid = $this->readPid();

        if (!$pid) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return true; // Windows: se asume activo si el pid file existe y el proceso coincide
    }

    protected function stopProcess(?int $pid): bool
    {
        if (!$pid) {
            return false;
        }

        if (function_exists('posix_kill')) {
            posix_kill($pid, 15); // SIGTERM
            return true;
        }

        // Windows
        exec(sprintf('taskkill /PID %d /F', $pid));

        return true;
    }
}