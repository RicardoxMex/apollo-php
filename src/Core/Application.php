<?php

namespace ApolloPHP\Core;

use ApolloPHP\Http\Request;
use ApolloPHP\Http\Response;
use ApolloPHP\Support\Config;
use ApolloPHP\Support\Env;
use ApolloPHP\Exceptions\HttpException;

class Application
{
    protected static ?Application $instance = null;
    protected Container $container;
    protected Kernel $kernel;
    protected Router $router;
    protected Config $config;
    protected ModuleRegistry $modules;
    protected string $basePath;
    protected bool $booted = false;
    protected array $events = [];
    
    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?: dirname(__DIR__, 3);
        
        // Singleton
        static::$instance = $this;
        
        // Inicializar componentes básicos primero
        $this->initializeCoreComponents();
        
        // Cargar configuración
        $this->loadConfiguration();
        
        // Inicializar entorno (después de tener config)
        $this->initializeEnvironment();
        
        // Registrar bindings básicos
        $this->registerBaseBindings();
    }
    
    public static function getInstance(): self
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }
        
        return static::$instance;
    }
    
    protected function initializeEnvironment(): void
    {
        // Cargar variables de entorno
        Env::load($this->basePath);
        
        // Configurar manejo de errores
        if ($this->isDebugMode()) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }
        
        // Configurar zona horaria
        date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));
    }
    
    protected function initializeCoreComponents(): void
    {
        $this->container = new Container();
        $this->config = new Config();
        $this->router = new Router();
        $this->modules = new ModuleRegistry($this);
        
        // Kernel se inicializa después del boot
        $this->kernel = new Kernel($this->container, $this->router);
    }
    
    protected function registerBaseBindings(): void
    {
        $this->container->instance('app', $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(Kernel::class, $this->kernel);
        
        // Bindings para interfaces
        $this->container->singleton(
            \Psr\Container\ContainerInterface::class,
            fn() => $this->container
        );
        
        $this->container->singleton(
            \Psr\Http\Message\RequestInterface::class,
            fn() => Request::createFromGlobals()
        );
    }
    
    protected function loadConfiguration(): void
    {
        // Cargar configuración base
        $configPath = $this->basePath . '/config';
        
        if (is_dir($configPath)) {
            $this->config->load($configPath);
        }
        
        // Configuración por defecto
        $this->config->set('app', array_merge([
            'name' => 'ApolloPHP',
            'env' => env('APP_ENV', 'production'),
            'debug' => env('APP_DEBUG', false),
            'url' => env('APP_URL', 'http://localhost'),
            'timezone' => env('APP_TIMEZONE', 'UTC'),
            'locale' => env('APP_LOCALE', 'en'),
            'key' => env('APP_KEY', ''),
            'cipher' => 'AES-256-CBC',
            'providers' => [],
            'aliases' => [],
        ], $this->config->get('app', [])));
    }
    
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }
        
        // Registrar service providers
        $this->registerServiceProviders();
        
        // Bootear módulos
        $this->modules->boot();
        
        // Cargar rutas
        $this->loadRoutes();
        
        // Disparar evento de boot
        $this->dispatch('boot', [$this]);
        
        $this->booted = true;
        
        return $this;
    }
    
    protected function registerServiceProviders(): void
    {
        $providers = $this->config->get('app.providers', []);
        
        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }
    }
    
    public function registerProvider($provider): ServiceProvider
    {
        if (is_string($provider)) {
            $provider = new $provider($this->container);
        }
        
        $provider->register();
        
        if (method_exists($provider, 'boot')) {
            $this->container->call([$provider, 'boot']);
        }
        
        return $provider;
    }
    
    protected function loadRoutes(): void
    {
        // Cargar rutas base
        $routesPath = $this->basePath . '/routes';
        
        if (is_dir($routesPath)) {
            $this->router->load($routesPath);
        }
        
        // Cargar rutas de módulos
        $this->modules->loadRoutes();
    }
    
    public function run(): void
    {
        try {
            $this->boot();
            
            $request = Request::createFromGlobals();
            $response = $this->kernel->handle($request);
            $response->send();
            
            $this->terminate($request, $response);
        } catch (\Throwable $e) {
            // In CLI mode, just output the error and exit with error code
            if ($this->runningInConsole()) {
                echo "Error: " . $e->getMessage() . "\n";
                exit(1);
            }
            throw $e;
        }
    }
    
    public function terminate(Request $request, Response $response): void
    {
        $this->dispatch('terminate', [$request, $response]);
    }
    
    public function module(string $name, ?string $namespace = null): Module
    {
        return $this->modules->register($name, $namespace);
    }
    
    public function getModules(): array
    {
        return $this->modules->all();
    }
    
    public function route(string $method, string $path, $handler): Router
    {
        return $this->router->add($method, $path, $handler);
    }
    
    public function get(string $path, $handler): Router
    {
        return $this->route('GET', $path, $handler);
    }
    
    public function post(string $path, $handler): Router
    {
        return $this->route('POST', $path, $handler);
    }
    
    public function put(string $path, $handler): Router
    {
        return $this->route('PUT', $path, $handler);
    }
    
    public function delete(string $path, $handler): Router
    {
        return $this->route('DELETE', $path, $handler);
    }
    
    public function patch(string $path, $handler): Router
    {
        return $this->route('PATCH', $path, $handler);
    }
    
    public function group(array $attributes, \Closure $callback): void
    {
        $this->router->group($attributes, $callback);
    }
    
    public function middleware($middleware): void
    {
        $this->router->middleware($middleware);
    }
    
    public function getContainer(): Container
    {
        return $this->container;
    }
    
    public function getConfig(): Config
    {
        return $this->config;
    }
    
    public function getRouter(): Router
    {
        return $this->router;
    }
    
    public function getKernel(): Kernel
    {
        return $this->kernel;
    }
    
    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
    
    public function configPath(string $path = ''): string
    {
        return $this->basePath('config' . ($path ? DIRECTORY_SEPARATOR . $path : $path));
    }
    
    public function databasePath(string $path = ''): string
    {
        return $this->basePath('database' . ($path ? DIRECTORY_SEPARATOR . $path : $path));
    }
    
    public function modulesPath(string $path = ''): string
    {
        return $this->basePath('modules' . ($path ? DIRECTORY_SEPARATOR . $path : $path));
    }
    
    public function storagePath(string $path = ''): string
    {
        return $this->basePath('storage' . ($path ? DIRECTORY_SEPARATOR . $path : $path));
    }
    
    public function publicPath(string $path = ''): string
    {
        return $this->basePath('public' . ($path ? DIRECTORY_SEPARATOR . $path : $path));
    }
    
    public function isDebugMode(): bool
    {
        return $this->config->get('app.debug', false);
    }
    
    public function environment(string|array $environments): bool
    {
        $current = $this->config->get('app.env', 'production');
        
        if (is_array($environments)) {
            return in_array($current, $environments);
        }
        
        return $current === $environments;
    }
    
    public function runningInConsole(): bool
    {
        return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
    }
    
    public function event(string $name, callable $listener): self
    {
        $this->events[$name][] = $listener;
        return $this;
    }
    
    public function dispatch(string $name, array $payload = []): void
    {
        foreach ($this->events[$name] ?? [] as $listener) {
            call_user_func_array($listener, $payload);
        }
    }
    
    public function __get(string $key)
    {
        return $this->container->get($key);
    }
    
    public function make(string $abstract, array $parameters = [])
    {
        return $this->container->make($abstract, $parameters);
    }
    
    public function __call(string $method, array $parameters)
    {
        return $this->container->call([$this->container, $method], $parameters);
    }
}