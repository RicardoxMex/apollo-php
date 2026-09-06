<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MakeRepositoryCommand extends Command
{
    protected string $signature = 'make:repository';
    protected string $description = 'Create a new repository class in an app';

    public function handle(): int
    {
        global $argv;

        if (count($argv) < 3) {
            $this->error('Repository name is required.');
            $this->line('Usage: php apollo make:repository <RepositoryName> [--app=<app>]');
            $this->line('Example: php apollo make:repository UserRepository --app=users');
            return 1;
        }

        $repoName = ucfirst(preg_replace('/[^A-Za-z0-9_]/', '', $argv[2]));
        $appName = ucfirst($this->getAppOption($argv) ?: 'users');

        if (!$repoName) {
            $this->error('Invalid repository name.');
            return 1;
        }

        $reposDir = __DIR__ . '/../../../apps/' . $appName . '/Repositories';

        if (!is_dir($reposDir) && !mkdir($reposDir, 0755, true) && !is_dir($reposDir)) {
            $this->error("Could not create directory: {$reposDir}");
            return 1;
        }

        $filePath = $reposDir . '/' . $repoName . '.php';

        if (file_exists($filePath)) {
            $this->error("Repository already exists: {$repoName}");
            return 1;
        }

        $stub = <<<PHP
<?php

namespace Apps\\{$appName}\\Repositories;

class {$repoName}
{
    // TODO: inyectar el modelo/QueryBuilder y escribir los accesos a datos.

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

        $this->info("✅ Repository created: {$repoName}");
        $this->line("  Location: " . (realpath($filePath) ?: $filePath));
        $this->line("  Namespace: Apps\\{$appName}\\Repositories");

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