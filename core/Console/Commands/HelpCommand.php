<?php
// core/Console/Commands/HelpCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class HelpCommand extends Command
{
    protected string $signature = 'help';
    protected string $description = 'Show available commands';

    public function handle(): int
    {
        $this->info('Apollo Framework CLI');
        $this->line();
        $this->line('Usage:');
        $this->line('  php apollo <command>');
        $this->line();
        $this->info('Available commands:');
        $this->line('  route:list       List all registered routes');
        $this->line('  make:controller  Create a new controller class');
        $this->line('  make:middleware  Create a new middleware class');
        $this->line('  system:report    Generate a system report');
        $this->line('  test             test');
        $this->line('  help             Show this help message');
        $this->line();

        return 0;
    }
}