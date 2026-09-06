<?php
// core/Console/Commands/HelpCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Console\Kernel;

class HelpCommand extends Command
{
    protected string $signature = 'help';
    protected string $description = 'Show available commands';

    public function handle(): int
    {
        $kernel = app()->make(Kernel::class);

        $this->info('Apollo Framework CLI');
        $this->line();
        $this->line('Usage:');
        $this->line('  php apollo <command>');
        $this->line();
        $this->info('Available commands:');

        foreach ($kernel->getCommands() as $name => $class) {
            $command = app()->make($class);
            $this->line('  ' . str_pad($name, 18) . $command->getDescription());
        }

        $this->line();

        return 0;
    }
}