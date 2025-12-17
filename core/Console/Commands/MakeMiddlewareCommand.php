<?php
// core/Console/Commands/MakeMiddlewareCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MakeMiddlewareCommand extends Command
{
    protected string $signature = 'make:middleware';
    protected string $description = 'Create a new middleware class';

    public function handle(): int
    {
        global $argv;
        
        if (count($argv) < 3) {
            $this->error('Middleware name is required.');
            $this->line('Usage: php apollo make:middleware <MiddlewareName> [--app=<app>]');
            return 1;
        }

        $middlewareName = $argv[2];
        $appName = $this->getAppOption($argv) ?: 'users'; // Default app

        if (!$this->validateMiddlewareName($middlewareName)) {
            $this->error('Invalid middleware name. Use PascalCase (e.g., AuthMiddleware)');
            return 1;
        }

        $this->createMiddleware($middlewareName, $appName);
        
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

    private function validateMiddlewareName(string $name): bool
    {
        return preg_match('/^[A-Z][a-zA-Z0-9]*Middleware$/', $name);
    }

    private function createMiddleware(string $middlewareName, string $appName): void
    {
        $appPath = "apps/{$appName}";
        $middlewarePath = "{$appPath}/Middleware";
        
        if (!is_dir($middlewarePath)) {
            mkdir($middlewarePath, 0755, true);
        }

        $filePath = "{$middlewarePath}/{$middlewareName}.php";
        
        if (file_exists($filePath)) {
            $this->error("Middleware {$middlewareName} already exists!");
            return;
        }

        $template = $this->getMiddlewareTemplate($middlewareName, $appName);
        
        file_put_contents($filePath, $template);
        
        $this->info("Middleware created successfully!");
        $this->line("Location: {$filePath}");
        $this->line();
        $this->warn("Don't forget to register the middleware in your ServiceProvider!");
    }

    private function getMiddlewareTemplate(string $middlewareName, string $appName): string
    {
        $namespace = "Apps\\" . ucfirst($appName) . "\\Middleware";
        
        return "<?php
// {$middlewareName}.php

namespace {$namespace};

use Apollo\Core\Http\Request;
use Closure;

class {$middlewareName}
{
    public function handle(Request \$request, Closure \$next)
    {
        // Add your middleware logic here
        // Example: Check authentication, validate input, etc.
        
        // Continue to next middleware or controller
        return \$next(\$request);
    }
}
";
    }
}