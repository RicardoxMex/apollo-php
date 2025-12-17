<?php
// core/Application.php

namespace Apollo\Core;

use Apollo\Core\Container\Container;
use Apollo\Core\Container\ServiceProvider;
use Apollo\Core\Http\Kernel;
use Apollo\Core\Http\Request;
use Exception;

class Application extends Container
{
    private string $basePath;
    private array $serviceProviders = [];
    private array $bootedProviders = [];
    private array $loadedApps = [];

    public function __construct(?string $basePath = null)
    {
        if ($basePath) {
            $this->setBasePath($basePath);
        }

        $this->registerBaseBindings();
        $this->registerCoreAliases();

        // Establecer esta instancia como singleton
        parent::setInstance($this);
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

        // Registrar configuraciones
        $this->singleton('config', function () {
            return new Config();
        });

        // Registrar router como singleton
        $this->singleton('router', function ($app) {
            return new Router\Router($app);
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

        if (!$this->isConsoleMode()) {
            error_log("🔍 Registering app: {$appName}");
        }

        // Leer app.json si existe
        $appJsonPath = $appPath . '/app.json';
        $appConfig = null;
        
        if (file_exists($appJsonPath)) {
            $appConfig = json_decode(file_get_contents($appJsonPath), true);
            if (!$this->isConsoleMode()) {
                error_log("✅ app.json loaded for {$appName}");
            }
        }

        // Cargar configuración de la app
        $configPath = $appPath . '/config';
        if (is_dir($configPath)) {
            $this->loadAppConfig($appName, $configPath);
            if (!$this->isConsoleMode()) {
                error_log("✅ Config loaded for {$appName}");
            }
        }

        // Registrar Service Providers desde app.json
        if ($appConfig && isset($appConfig['providers']) && is_array($appConfig['providers'])) {
            foreach ($appConfig['providers'] as $providerClass) {
                if (class_exists($providerClass)) {
                    $this->registerServiceProvider($providerClass);
                    if (!$this->isConsoleMode()) {
                        error_log("✅ ServiceProvider registered: {$providerClass}");
                    }
                } else {
                    if (!$this->isConsoleMode()) {
                        error_log("⚠️  ServiceProvider not found: {$providerClass}");
                    }
                }
            }
        } else {
            // Fallback: buscar ServiceProvider con nombre convencional
            $providerClass = "Apps\\{$appName}\\{$appName}ServiceProvider";
            if (class_exists($providerClass)) {
                $this->registerServiceProvider($providerClass);
                if (!$this->isConsoleMode()) {
                    error_log("✅ ServiceProvider registered (fallback): {$providerClass}");
                }
            }
        }

        // Determinar el prefix de las rutas
        $prefix = $appConfig['prefix'] ?? "api/{$appName}";

        // Cargar rutas desde app.json o desde directorio Routes
        if ($appConfig && isset($appConfig['routes']) && is_array($appConfig['routes'])) {
            $routesPath = $appPath . '/Routes';
            foreach ($appConfig['routes'] as $routeFile) {
                $fullRoutePath = $routesPath . '/' . $routeFile;
                if (file_exists($fullRoutePath)) {
                    $this->loadAppRoute($appName, $fullRoutePath, $prefix);
                    if (!$this->isConsoleMode()) {
                        error_log("✅ Route loaded: {$routeFile} with prefix: {$prefix}");
                    }
                } else {
                    if (!$this->isConsoleMode()) {
                        error_log("⚠️  Route file not found: {$fullRoutePath}");
                    }
                }
            }
        } else {
            // Fallback: cargar todas las rutas del directorio Routes
            $routesPath = $appPath . '/Routes';
            if (is_dir($routesPath)) {
                if (!$this->isConsoleMode()) {
                    error_log("🔍 Loading routes from: {$routesPath}");
                }
                $this->loadAppRoutes($appName, $routesPath, $prefix);
                if (!$this->isConsoleMode()) {
                    error_log("✅ Routes loaded for {$appName}");
                }
            }
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

    private function loadAppRoutes(string $appName, string $routesPath, ?string $prefix = null): void
    {
        $router = $this->make('router');
        $routeFiles = glob($routesPath . '/*.php');
        
        $prefix = $prefix ?? "api/{$appName}";
        
        foreach ($routeFiles as $routeFile) {
            $this->loadAppRoute($appName, $routeFile, $prefix);
        }
        
        // Reconstruir el índice de rutas nombradas después de cargar todas las rutas
        $router->getRouteCollection()->rebuildNamedRoutes();
    }

    private function loadAppRoute(string $appName, string $routeFile, ?string $prefix = null): void
    {
        $router = $this->make('router');
        
        $prefix = $prefix ?? "api/{$appName}";
        
        // Ejecutar dentro del grupo
        $router->group([
            'prefix' => $prefix,
            'namespace' => "Apps\\{$appName}\\Controllers"
        ], function ($router) use ($routeFile) {
            // Incluir el archivo con $router disponible en el scope
            require $routeFile;
        });
    }

    public function handle(?Request $request = null)
    {
        if (!$request) {
            $request = Request::capture();
        }

        $kernel = $this->make(Kernel::class);

        return $kernel->handle($request);
    }

    public function run(?Request $request = null)
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

    private function isConsoleMode(): bool
    {
        return php_sapi_name() === 'cli';
    }
}