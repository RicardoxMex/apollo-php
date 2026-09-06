<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MakeAppCommand extends Command
{
    protected string $signature = 'make:app';
    protected string $description = 'Create a new modular app (scaffold completo)';

    public function handle(): int
    {
        global $argv;

        if (count($argv) < 3) {
            $this->error('App name is required.');
            $this->line('Usage: php apollo make:app <AppName>');
            $this->line('Example: php apollo make:app Blog');
            return 1;
        }

        $appName = ucfirst(preg_replace('/[^A-Za-z0-9_]/', '', $argv[2]));
        $appKey = strtolower($appName);

        if (!$appName) {
            $this->error('Invalid app name.');
            return 1;
        }

        $appsRoot = realpath(__DIR__ . '/../../../apps');
        $appPath = $appsRoot . '/' . $appName;

        if (is_dir($appPath)) {
            $this->error("App '{$appName}' already exists.");
            return 1;
        }

        // Directorios estándar de una app
        $dirs = [
            'Controllers', 'Middleware', 'Models', 'Providers',
            'Repositories', 'Routes', 'Services', 'config',
        ];

        foreach ($dirs as $dir) {
            mkdir($appPath . '/' . $dir, 0755, true);
        }

        // app.json
        $appJson = json_encode([
            'name' => $appKey,
            'version' => '1.0.0',
            'description' => "{$appName} management app",
            'author' => 'Your Name',
            'prefix' => "api/{$appKey}",
            'providers' => ["Apps\\{$appName}\\Providers\\{$appName}ServiceProvider"],
            'routes' => ['api.php'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($appPath . '/app.json', $appJson . "\n");

        // ServiceProvider
        $this->writeFile($appPath . "/Providers/{$appName}ServiceProvider.php", <<<PHP
<?php

namespace Apps\\{$appName}\\Providers;

use Apollo\\Core\\Container\\ServiceProvider;

class {$appName}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registrar bindings del container (repos, services, controllers, middleware)
    }

    public function boot(): void
    {
        // Las rutas se cargan automáticamente desde Routes/api.php
    }
}

PHP);

        // Controller de ejemplo (sin DB)
        $this->writeFile($appPath . "/Controllers/{$appName}Controller.php", <<<PHP
<?php

namespace Apps\\{$appName}\\Controllers;

use Apollo\\Core\\Http\\Controller;
use Apollo\\Core\\Http\\Request;
use Apollo\\Core\\Http\\Response;

class {$appName}Controller extends Controller
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
            'id' => \$id
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

PHP);

        // Rutas de ejemplo (lecturas públicas, escrituras con auth)
        $this->writeFile($appPath . '/Routes/api.php', <<<PHP
<?php
// apps/{$appName}/Routes/api.php

use Apps\\{$appName}\\Controllers\\{$appName}Controller;

/** @var \\Apollo\\Core\\Router\\Router \$router */

// Lecturas públicas
\$router->get('/', [{$appName}Controller::class, 'index'])->name('{$appKey}.index');
\$router->get('/{id}', [{$appName}Controller::class, 'show'])->where(['id' => '\\d+'])->name('{$appKey}.show');

// Escrituras protegidas (requieren token JWT válido: Authorization: Bearer <token>)
\$router->group(['middleware' => ['auth']], function(\$router) {
    \$router->post('/', [{$appName}Controller::class, 'store'])->name('{$appKey}.store');
    \$router->put('/{id}', [{$appName}Controller::class, 'update'])->where(['id' => '\\d+'])->name('{$appKey}.update');
    \$router->delete('/{id}', [{$appName}Controller::class, 'destroy'])->where(['id' => '\\d+'])->name('{$appKey}.destroy');
});

PHP);

        // Config vacía de la app
        $this->writeFile($appPath . '/config/app.php', "<?php\n\nreturn [\n    // Configuración específica de la app\n];\n");

        // Registrar la app en config/apps.php
        $this->registerInConfigApps($appName);

        // Registrar en apollo.json (informacional)
        $this->registerInApolloJson($appKey);

        $this->info("✅ App created: {$appName}");
        $this->line("  Location: {$appPath}");
        $this->line("  Prefix:   api/{$appKey}");
        $this->line("  Register: run `php apollo route:list` to verify");

        return 0;
    }

    private function writeFile(string $path, string $content): void
    {
        file_put_contents($path, $content);
    }

    private function registerInConfigApps(string $appName): void
    {
        $file = realpath(__DIR__ . '/../../../config') . '/apps.php';
        $content = file_get_contents($file);

        if ($content === false || str_contains($content, "'{$appName}'")) {
            return;
        }

        // Insertar como primer elemento del array 'registered' (tolera CRLF de Windows)
        $updated = preg_replace(
            "/(\x27registered\x27 => \[\r?\n)/",
            "$1        '{$appName}',\n",
            $content,
            1
        );

        file_put_contents($file, $updated !== null ? $updated : $content);
    }

    private function registerInApolloJson(string $appKey): void
    {
        $file = realpath(__DIR__ . '/../../..') . '/apollo.json';
        $config = json_decode(file_get_contents($file), true);

        if (!is_array($config) || !isset($config['apps']) || in_array($appKey, $config['apps'], true)) {
            return;
        }

        $config['apps'][] = $appKey;

        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}