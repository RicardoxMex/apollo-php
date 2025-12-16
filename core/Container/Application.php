<?php
// core/Application.php

namespace Apollo\Core;

use Apollo\Core\Container\Container;
use Apollo\Core\Container\ServiceProvider;
use Apollo\Core\Http\Kernel;
use Apollo\Core\Http\Request;
use Apollo\Core\Router\Router;
use Exception;

class Application extends Container
{
    private string $basePath;
    private array $serviceProviders = [];
    private array $bootedProviders = [];
    private array $loadedApps = [];

    public function __construct(string $basePath = null)
    {
        // Establecer esta instancia como la instancia singleton del Container
        parent::setInstance($this);

        if ($basePath) {
            $this->setBasePath($basePath);
        }

        $this->registerBaseBindings();
        $this->registerCoreAliases();
    }

    private function setBasePath(string $path): void
    {
        $this->basePath = rtrim($path, '\/');

        $this->instance('path', $this->basePath);
        $this->instance('path.apps', $this->basePath . '/apps');
        $this->instance('path.core', $this->basePath . '/core');
        $this->instance('path.config', $this->basePath . '/config');
        $this->instance('path.public', $this->basePath . '/public');
        $this->instance('path.database', $this->basePath . '/database');
    }

    private function registerBaseBindings(): void
    {
        $this->instance('app', $this);
        $this->instance(Container::class, $this);

        $this->singleton(Config::class, function () {
            $config = new Config();

            // Cargar configuración global
            $configPath = $this->make('path.config');
            if (is_dir($configPath)) {
                foreach (glob($configPath . '/*.php') as $configFile) {
                    $key = pathinfo($configFile, PATHINFO_FILENAME);
                    $config->set($key, require $configFile);
                }
            }

            return $config;
        });

        // Y alias para 'config'
        $this->alias('config', Config::class);

        // Registrar router
        $this->singleton(Router::class, function () {
            return new Router($this);
        });

        $this->alias('router', Router::class);

        // Registrar kernel
        $this->singleton(Kernel::class, function () {
            return new Kernel(
                $this,
                $this->make(Router::class)
            );
        });
    }

    private function registerCoreAliases(): void
    {
        $aliases = [
            'app' => [self::class, Container::class],
            'config' => [Config::class],
            'router' => [Router\Router::class],
            'request' => [Http\Request::class],
            'response' => [Http\Response::class],
        ];

        foreach ($aliases as $key => $aliasesList) {
            foreach ($aliasesList as $alias) {
                $this->alias($key, $alias);
            }
        }
    }

    public function registerServiceProvider($provider): ServiceProvider
    {
        if (is_string($provider)) {
            $provider = $this->make($provider);
        }

        $provider->register();

        $this->serviceProviders[] = $provider;

        return $provider;
    }

    public function bootServiceProviders(): void
    {
        foreach ($this->serviceProviders as $provider) {
            if (!in_array($provider, $this->bootedProviders, true)) {
                $provider->boot();
                $this->bootedProviders[] = $provider;
            }
        }
    }

    public function registerApp(string $appName): void
    {
        if (in_array($appName, $this->loadedApps)) {
            return;
        }

        $appPath = $this->make('path.apps') . '/' . $appName;

        if (!is_dir($appPath)) {
            throw new Exception("App '{$appName}' not found in apps directory");
        }

        // Cargar configuración de la app
        $configPath = $appPath . '/config';
        if (is_dir($configPath)) {
            $this->loadAppConfig($appName, $configPath);
        }

        // Registrar Service Provider de la app
        $providerClass = "Apps\\{$appName}\\{$appName}ServiceProvider";
        if (class_exists($providerClass)) {
            $this->registerServiceProvider($providerClass);
        }

        // Cargar rutas de la app
        $routesPath = $appPath . '/Routes';
        if (is_dir($routesPath)) {
            $this->loadAppRoutes($appName, $routesPath);
        }

        $this->loadedApps[] = $appName;
    }

    private function loadAppConfig(string $appName, string $configPath): void
    {
        $config = $this->make('config');

        foreach (glob($configPath . '/*.php') as $configFile) {
            $key = pathinfo($configFile, PATHINFO_FILENAME);
            $config->set("apps.{$appName}.{$key}", require $configFile);
        }
    }

    private function loadAppRoutes(string $appName, string $routesPath): void
    {
        $router = $this->make('router');

        foreach (glob($routesPath . '/*.php') as $routeFile) {
            $router->group(
                ['namespace' => "Apps\\{$appName}\\Controllers", 'prefix' => $appName],
                function ($router) use ($routeFile) {
                    require $routeFile;
                }
            );
        }
    }

    public function handle(Request $request = null)
    {
        if (!$request) {
            $request = Request::capture();
        }

        $kernel = $this->make(Kernel::class);

        return $kernel->handle($request);
    }

    public function run(Request $request = null)
    {
        $response = $this->handle($request);
        $response->send();
    }

    public function getLoadedApps(): array
    {
        return $this->loadedApps;
    }

    public function version(): string
    {
        return '1.0.0-alpha';
    }
}