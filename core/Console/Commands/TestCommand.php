<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;

class TestCommand extends Command
{
    protected string $signature = 'test';
    protected string $description = 'Generate a system report';
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }
    
    public function handle(): int
    {
        $this->info('test command');
        return 0;
    }
}