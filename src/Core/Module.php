<?php

namespace ApolloPHP\Core;

use ReflectionClass;

class Module
{
    protected string $name;
    protected string $path;
    protected Application $app;
    protected array $config = [];
    protected bool $booted = false;
    protected array $providers = [];
    protected array $commands = [];
    protected array $migrations = [];
    protected array $factories = [];
    protected array $seeders = [];
    protected array $routes = [];
    protected array $views = [];
    protected array $assets = [];

    public function __construct(string $name, string $path, Application $app)
    {
        $this->name = $name;
        $this->path = $path;
        $this->app = $app;

        $this->loadConfig();
        $this->registerProviders();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->bootProviders();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerCommands();

        $this->booted = true;
    }

    protected function loadConfig(): void
    {
        $configFile = $this->path . '/config.php';

        if (file_exists($configFile)) {
            $this->config = require $configFile;

            // Registrar configuración en el app config
            $this->app->getConfig()->set("modules.{$this->name}", $this->config);
        }
    }

    protected function registerProviders(): void
    {
        $providersFile = $this->path . '/providers.php';

        if (file_exists($providersFile)) {
            $this->providers = require $providersFile;

            foreach ($this->providers as $provider) {
                $this->app->registerProvider($provider);
            }
        }
    }

    protected function bootProviders(): void
    {
        foreach ($this->providers as $provider) {
            $instance = $this->app->getContainer()->make($provider);
            if (method_exists($instance, 'boot')) {
                // Usar call() aquí también
                $this->app->getContainer()->call([$instance, 'boot']);
            }
        }
    }

    protected function registerRoutes(): void
    {
        $routesFile = $this->path . '/routes.php';

        if (file_exists($routesFile)) {
            $prefix = $this->config['prefix'] ?? "/api/{$this->name}";
            $middleware = $this->config['middleware'] ?? [];

            $this->app->group([
                'prefix' => $prefix,
                'middleware' => $middleware,
            ], function () use ($routesFile) {
                require $routesFile;
            });
        }
    }

    protected function registerViews(): void
    {
        $viewsPath = $this->path . '/views';

        if (is_dir($viewsPath)) {
            $this->views[] = $viewsPath;

            // Registrar en el view finder si existe
            if ($this->app->getContainer()->has('view')) {
                $viewFinder = $this->app->getContainer()->get('view.finder');
                $viewFinder->addLocation($viewsPath);
            }
        }
    }

    protected function registerCommands(): void
    {
        $commandsFile = $this->path . '/commands.php';

        if (file_exists($commandsFile)) {
            $this->commands = require $commandsFile;

            foreach ($this->commands as $command) {
                $this->app->getContainer()->singleton($command);

                if ($this->runningInConsole() && $this->app->getContainer()->has('console')) {
                    $this->app->getContainer()->get('console')->add(
                        $this->app->getContainer()->make($command)
                    );
                }
            }
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getNamespace(): string
    {
        $composer = json_decode(file_get_contents($this->path . '/composer.json'), true);

        foreach ($composer['autoload']['psr-4'] ?? [] as $namespace => $path) {
            if ($path === 'src/') {
                return rtrim($namespace, '\\');
            }
        }

        return 'Modules\\' . $this->name;
    }

    public function getSrcPath(): string
    {
        $srcPath = $this->path . '/src';
        return is_dir($srcPath) ? $srcPath : $this->path;
    }

    public function getMigrations(): array
    {
        if (empty($this->migrations)) {
            $migrationsPath = $this->path . '/database/migrations';

            if (is_dir($migrationsPath)) {
                $this->migrations = glob($migrationsPath . '/*.php');
            }
        }

        return $this->migrations;
    }

    public function publish(array $paths): void
    {
        foreach ($paths as $from => $to) {
            $source = $this->path . '/' . $from;
            $destination = $this->app->basePath($to);

            if (is_dir($source)) {
                $this->copyDirectory($source, $destination);
            } elseif (file_exists($source)) {
                @mkdir(dirname($destination), 0755, true);
                copy($source, $destination);
            }
        }
    }

    protected function copyDirectory(string $source, string $destination): void
    {
        @mkdir($destination, 0755, true);

        $dir = opendir($source);

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcFile = $source . '/' . $file;
            $destFile = $destination . '/' . $file;

            if (is_dir($srcFile)) {
                $this->copyDirectory($srcFile, $destFile);
            } else {
                copy($srcFile, $destFile);
            }
        }

        closedir($dir);
    }

    protected function runningInConsole(): bool
    {
        return $this->app->runningInConsole();
    }

    public function __call(string $method, array $parameters)
    {
        return $this->app->$method(...$parameters);
    }
}