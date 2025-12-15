<?php

namespace ApolloPHP\Core;

use ApolloPHP\Http\Request;
use ApolloPHP\Http\Response;
use ApolloPHP\Exceptions\HttpException;
use Closure;

class Router
{
    protected array $routes = [];
    protected array $currentGroup = [];
    protected array $patterns = [
        '{id}' => '(\d+)',
        '{slug}' => '([a-z0-9-]+)',
        '{uuid}' => '([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})',
        '{any}' => '(.+)',
    ];
    protected array $namedRoutes = [];
    
    public function __construct()
    {
        $this->routes = [
            'GET' => [],
            'POST' => [],
            'PUT' => [],
            'PATCH' => [],
            'DELETE' => [],
            'OPTIONS' => [],
        ];
    }
    
    public function add(string $method, string $path, $handler, ?string $name = null): self
    {
        $method = strtoupper($method);
        $route = $this->applyGroupAttributes($path);
        
        $routeData = [
            'handler' => $handler,
            'middleware' => $route['middleware'],
            'prefix' => $route['prefix'],
            'original_path' => $path,
        ];
        
        $this->routes[$method][$route['path']] = $routeData;
        
        if ($name) {
            $this->namedRoutes[$name] = [
                'method' => $method,
                'path' => $route['path'],
            ];
        }
        
        return $this;
    }
    
    public function get(string $path, $handler, ?string $name = null): self
    {
        return $this->add('GET', $path, $handler, $name);
    }
    
    public function post(string $path, $handler, ?string $name = null): self
    {
        return $this->add('POST', $path, $handler, $name);
    }
    
    public function put(string $path, $handler, ?string $name = null): self
    {
        return $this->add('PUT', $path, $handler, $name);
    }
    
    public function patch(string $path, $handler, ?string $name = null): self
    {
        return $this->add('PATCH', $path, $handler, $name);
    }
    
    public function delete(string $path, $handler, ?string $name = null): self
    {
        return $this->add('DELETE', $path, $handler, $name);
    }
    
    public function options(string $path, $handler, ?string $name = null): self
    {
        return $this->add('OPTIONS', $path, $handler, $name);
    }
    
    public function match(array $methods, string $path, $handler, ?string $name = null): self
    {
        foreach ($methods as $method) {
            $this->add($method, $path, $handler, $name);
        }
        
        return $this;
    }
    
    public function any(string $path, $handler, ?string $name = null): self
    {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $path, $handler, $name);
    }
    
    public function group(array $attributes, Closure $callback): void
    {
        $previousGroup = $this->currentGroup;
        
        $this->currentGroup = array_merge($previousGroup, [
            'prefix' => trim(($previousGroup['prefix'] ?? '') . ($attributes['prefix'] ?? ''), '/'),
            'middleware' => array_merge($previousGroup['middleware'] ?? [], $attributes['middleware'] ?? []),
            'name' => ($previousGroup['name'] ?? '') . ($attributes['as'] ?? ''),
        ]);
        
        $callback($this);
        
        $this->currentGroup = $previousGroup;
    }
    
    public function middleware($middleware): self
    {
        $lastKey = array_key_last($this->routes['GET']);
        
        if ($lastKey !== null) {
            $this->routes['GET'][$lastKey]['middleware'][] = $middleware;
        }
        
        return $this;
    }
    
    public function name(string $name): self
    {
        $lastKey = array_key_last($this->routes['GET']);
        
        if ($lastKey !== null) {
            $this->namedRoutes[$name] = [
                'method' => 'GET',
                'path' => $lastKey,
            ];
        }
        
        return $this;
    }
    
    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = '/' . trim($request->getPath(), '/');
        
        $route = $this->findRoute($method, $path);
        
        if (!$route) {
            throw HttpException::notFound();
        }
        
        return $this->runRoute($request, $route);
    }
    
    protected function findRoute(string $method, string $path): ?array
    {
        // Buscar ruta exacta primero
        if (isset($this->routes[$method][$path])) {
            return $this->routes[$method][$path];
        }
        
        // Buscar rutas con parámetros
        foreach ($this->routes[$method] as $routePath => $route) {
            $pattern = $this->compilePattern($routePath);
            
            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches);
                $route['parameters'] = $this->parseParameters($routePath, $matches);
                return $route;
            }
        }
        
        return null;
    }
    
    protected function runRoute(Request $request, array $route): Response
    {
        // Añadir parámetros a la request
        if (isset($route['parameters'])) {
            foreach ($route['parameters'] as $key => $value) {
                $request->setAttribute($key, $value);
            }
        }
        
        // Ejecutar middleware
        if (!empty($route['middleware'])) {
            return $this->runMiddleware($request, $route);
        }
        
        // Ejecutar handler
        return $this->runHandler($request, $route['handler']);
    }
    
    protected function runMiddleware(Request $request, array $route)
    {
        $pipeline = new \ApolloPHP\Http\Middleware\Pipeline(
            app()->getContainer()
        );
        
        return $pipeline->send($request)
            ->through($route['middleware'])
            ->then(function ($request) use ($route) {
                return $this->runHandler($request, $route['handler']);
            });
    }
    
    protected function runHandler(Request $request, $handler)
    {
        if ($handler instanceof Closure) {
            return $handler($request, new Response());
        }
        
        if (is_string($handler) && strpos($handler, '@') !== false) {
            [$controller, $method] = explode('@', $handler);
            
            if (!class_exists($controller)) {
                throw new \Exception("Controller {$controller} not found");
            }
            
            $controllerInstance = app()->getContainer()->make($controller);
            
            if (!method_exists($controllerInstance, $method)) {
                throw new \Exception("Method {$method} not found in {$controller}");
            }
            
            return app()->getContainer()->call([$controllerInstance, $method], [
                'request' => $request,
            ]);
        }
        
        if (is_array($handler)) {
            [$controller, $method] = $handler;
            
            if (is_string($controller)) {
                $controller = app()->getContainer()->make($controller);
            }
            
            return app()->getContainer()->call([$controller, $method], [
                'request' => $request,
            ]);
        }
        
        throw new \Exception('Invalid route handler');
    }
    
    protected function compilePattern(string $path): string
    {
        $pattern = preg_quote($path, '/');
        
        foreach ($this->patterns as $key => $regex) {
            $pattern = str_replace(preg_quote($key, '/'), $regex, $pattern);
        }
        
        return '/^' . str_replace('/', '\/', $pattern) . '$/';
    }
    
    protected function parseParameters(string $path, array $matches): array
    {
        preg_match_all('/\{(\w+)\}/', $path, $paramNames);
        
        $parameters = [];
        foreach ($paramNames[1] as $index => $name) {
            $parameters[$name] = $matches[$index] ?? null;
        }
        
        return $parameters;
    }
    
    protected function applyGroupAttributes(string $path): array
    {
        $prefix = $this->currentGroup['prefix'] ?? '';
        $middleware = $this->currentGroup['middleware'] ?? [];
        $namePrefix = $this->currentGroup['name'] ?? '';
        
        $fullPath = $prefix ? '/' . trim($prefix . '/' . trim($path, '/'), '/') : '/' . trim($path, '/');
        
        return [
            'path' => $fullPath === '/' ? '/' : rtrim($fullPath, '/'),
            'middleware' => $middleware,
            'prefix' => $prefix,
            'name_prefix' => $namePrefix,
        ];
    }
    
    public function load(string $path): void
    {
        if (is_dir($path)) {
            // Cargar todos los archivos PHP del directorio
            $files = glob($path . '/*.php');
            
            foreach ($files as $file) {
                $this->loadRouteFile($file);
            }
        } elseif (file_exists($path)) {
            // Cargar un archivo específico
            $this->loadRouteFile($path);
        }
    }
    
    protected function loadRouteFile(string $file): void
    {
        // Guardar el router actual para restaurarlo después
        $previousRouter = $this;
        
        // Crear un nuevo router temporal para el archivo
        $router = $this;
        
        // Incluir el archivo con el router disponible como $router
        (function () use ($file, $router) {
            require $file;
        })();
    }
    
    public function getRoutes(): array
    {
        return $this->routes;
    }
    
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }
    
    public function url(string $name, array $parameters = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \Exception("Route named '{$name}' not found");
        }
        
        $route = $this->namedRoutes[$name];
        $path = $route['path'];
        
        // Reemplazar parámetros en la ruta
        foreach ($parameters as $key => $value) {
            $path = str_replace('{' . $key . '}', $value, $path);
        }
        
        // Remover parámetros no utilizados (opcionales)
        $path = preg_replace('/\{[^}]+\}/', '', $path);
        $path = rtrim($path, '/');
        
        return $path ?: '/';
    }
    
    public function current(): ?array
    {
        $request = Request::createFromGlobals();
        $method = $request->getMethod();
        $path = '/' . trim($request->getPath(), '/');
        
        return $this->findRoute($method, $path);
    }
    
    public function currentRouteName(): ?string
    {
        $route = $this->current();
        
        if (!$route) {
            return null;
        }
        
        foreach ($this->namedRoutes as $name => $namedRoute) {
            if ($namedRoute['path'] === $route['path']) {
                return $name;
            }
        }
        
        return null;
    }
}