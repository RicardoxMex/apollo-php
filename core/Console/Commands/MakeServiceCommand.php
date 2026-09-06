<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MakeServiceCommand extends Command
{
    protected string $signature = 'make:service';
    protected string $description = 'Create a new service class in an app';

    public function handle(): int
    {
        global $argv;

        if (count($argv) < 3) {
            $this->error('Service name is required.');
            $this->line('Usage: php apollo make:service <ServiceName> [--app=<app>]');
            $this->line('Example: php apollo make:service UserService --app=users');
            return 1;
        }

        $serviceName = ucfirst(preg_replace('/[^A-Za-z0-9_]/', '', $argv[2]));
        $appName = ucfirst($this->getAppOption($argv) ?: 'users');

        if (!$serviceName) {
            $this->error('Invalid service name.');
            return 1;
        }

        $servicesDir = __DIR__ . '/../../../apps/' . $appName . '/Services';

        if (!is_dir($servicesDir) && !mkdir($servicesDir, 0755, true) && !is_dir($servicesDir)) {
            $this->error("Could not create directory: {$servicesDir}");
            return 1;
        }

        $filePath = $servicesDir . '/' . $serviceName . '.php';

        if (file_exists($filePath)) {
            $this->error("Service already exists: {$serviceName}");
            return 1;
        }

        $stub = <<<PHP
<?php

namespace Apps\\{$appName}\\Services;

class {$serviceName}
{
    /**
     * TODO: inyectar modelos/repositorios y escribir la lógica de negocio.
     * Regístralo en el container si lo necesita (ver {$appName}ServiceProvider).
     */
    public function all(): array
    {
        return [];
    }

    public function find(int \$id): ?array
    {
        return null;
    }
}

PHP;

        if (file_put_contents($filePath, $stub) === false) {
            $this->error("Could not write file: {$filePath}");
            return 1;
        }

        $this->info("✅ Service created: {$serviceName}");
        $this->line("  Location: " . (realpath($filePath) ?: $filePath));
        $this->line("  Namespace: Apps\\{$appName}\\Services");

        return 0;
    }

    private function getAppOption(array $argv): ?string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--app=')) {
                return substr($arg, 6);
            }
        }

        return null;
    }
}