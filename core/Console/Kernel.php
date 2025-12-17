<?php
// core/Console/Kernel.php

namespace Apollo\Core\Console;

use Apollo\Core\Container\Container;

class Kernel
{
    private Container $app;
    private array $commands = [];

    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        $this->commands = [
            'route:list' => Commands\RouteListCommand::class,
            'make:controller' => Commands\MakeControllerCommand::class,
            'make:middleware' => Commands\MakeMiddlewareCommand::class,
            'system:report' => Commands\SystemReportCommand::class,
            'test' => Commands\TestCommand::class,
            'help' => Commands\HelpCommand::class,
        ];
    }

    public function handle(array $argv): int
    {
        if (count($argv) < 2) {
            $this->showHelp();
            return 0;
        }

        $commandName = $argv[1];

        if (!isset($this->commands[$commandName])) {
            echo "Command '{$commandName}' not found.\n";
            $this->showHelp();
            return 1;
        }

        $commandClass = $this->commands[$commandName];
        $command = $this->app->make($commandClass);

        return $command->handle();
    }

    private function showHelp(): void
    {
        echo "Apollo Framework CLI\n\n";
        echo "Available commands:\n";
        
        foreach ($this->commands as $name => $class) {
            $command = $this->app->make($class);
            echo "  {$name}    {$command->getDescription()}\n";
        }
    }
}