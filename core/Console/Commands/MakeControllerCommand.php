<?php
// core/Console/Commands/MakeControllerCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MakeControllerCommand extends Command
{
    protected string $signature = 'make:controller';
    protected string $description = 'Create a new controller class';

    public function handle(): int
    {
        global $argv;
        
        if (count($argv) < 3) {
            $this->error('Controller name is required.');
            $this->line('Usage: php apollo make:controller <ControllerName> [--app=<app>]');
            return 1;
        }

        $controllerName = $argv[2];
        $appName = $this->getAppOption($argv) ?: 'users'; // Default app

        if (!$this->validateControllerName($controllerName)) {
            $this->error('Invalid controller name. Use PascalCase (e.g., UserController)');
            return 1;
        }

        $this->createController($controllerName, $appName);
        
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

    private function validateControllerName(string $name): bool
    {
        return preg_match('/^[A-Z][a-zA-Z0-9]*Controller$/', $name);
    }

    private function createController(string $controllerName, string $appName): void
    {
        $appPath = "apps/{$appName}";
        $controllersPath = "{$appPath}/Controllers";
        
        if (!is_dir($controllersPath)) {
            mkdir($controllersPath, 0755, true);
        }

        $filePath = "{$controllersPath}/{$controllerName}.php";
        
        if (file_exists($filePath)) {
            $this->error("Controller {$controllerName} already exists!");
            return;
        }

        $template = $this->getControllerTemplate($controllerName, $appName);
        
        file_put_contents($filePath, $template);
        
        $this->info("Controller created successfully!");
        $this->line("Location: {$filePath}");
    }

    private function getControllerTemplate(string $controllerName, string $appName): string
    {
        $namespace = "Apps\\" . ucfirst($appName) . "\\Controllers";
        
        return "<?php
// {$controllerName}.php

namespace {$namespace};

use Apollo\Core\Http\Controller;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;

class {$controllerName} extends Controller
{
    public function index(Request \$request): Response
    {
        return Response::json([
            'message' => 'Index method',
            'data' => []
        ]);
    }

    public function show(Request \$request, int \$id): Response
    {
        return Response::json([
            'message' => 'Show method',
            'id' => \$id
        ]);
    }

    public function store(Request \$request): Response
    {
        return Response::json([
            'message' => 'Store method',
            'data' => \$request->all()
        ], 201);
    }

    public function update(Request \$request, int \$id): Response
    {
        return Response::json([
            'message' => 'Update method',
            'id' => \$id,
            'data' => \$request->all()
        ]);
    }

    public function destroy(Request \$request, int \$id): Response
    {
        return Response::json([
            'message' => 'Destroy method',
            'id' => \$id
        ]);
    }
}
";
    }
}