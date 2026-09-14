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
            'make:app' => Commands\MakeAppCommand::class,
            'make:controller' => Commands\MakeControllerCommand::class,
            'make:middleware' => Commands\MakeMiddlewareCommand::class,
            'make:migration' => Commands\MakeMigrationCommand::class,
            'make:model' => Commands\MakeModelCommand::class,
            'make:repository' => Commands\MakeRepositoryCommand::class,
            'make:seeder' => Commands\MakeSeederCommand::class,
            'make:service' => Commands\MakeServiceCommand::class,
            'db:setup' => Commands\DatabaseSetupCommand::class,
            'db:seed' => Commands\DatabaseSeedCommand::class,
            'db:refresh' => Commands\DatabaseRefreshCommand::class,
            'migrate' => Commands\MigrateCommand::class,
            'migrate:rollback' => Commands\MigrateRollbackCommand::class,
            'migrate:reset' => Commands\MigrateResetCommand::class,
            'migrate:status' => Commands\MigrationStatusCommand::class,
            'realtime:start' => Commands\RealtimeStartCommand::class,
            'realtime:stop' => Commands\RealtimeStopCommand::class,
            'realtime:restart' => Commands\RealtimeRestartCommand::class,
            'realtime:status' => Commands\RealtimeStatusCommand::class,
            'realtime:test' => Commands\RealtimeTestCommand::class,
            'system:report' => Commands\SystemReportCommand::class,
            'test' => Commands\TestCommand::class,
            'test:middleware' => Commands\MiddlewareTestCommand::class,
            'help' => Commands\HelpCommand::class,
        ];
    }

    public function getCommands(): array
    {
        return $this->commands;
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
        
        foreach ($this->getCommands() as $name => $class) {
            $command = $this->app->make($class);
            echo "  " . str_pad($name, 18) . $command->getDescription() . "\n";
        }
    }
}