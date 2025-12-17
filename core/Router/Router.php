<?php
// core/Router/Router.php

namespace Apollo\Core\Router;

use Closure;
use Apollo\Core\Container\Container;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use InvalidArgumentException;

class Router
{
    private RouteCollection $routes;
    private Container $container;
    private array $groupStack = [];
    private array $middleware = [];
    private array $globalMiddleware = [];
    private array $patterns = [
        'id' => '\d+',
        'uuid' => '[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}',
        'slug' => '[a-z0-9-]+',
    ];

    public function __construct(Container $container)
    {
        $this->routes = new RouteCollection();
        $this->container = $container;
    }

    public function addRoute(string $method, string $uri, $action): Route
    {
        $route = $this->createRoute($method, $uri, $action);

        // Aplicar atributos de todos los grupos en la pila (del más externo al más interno)
        foreach ($this->groupStack as $groupAttributes) {
            $route = $this->mergeGroupAttributes($route, $groupAttributes);
        }

        $this->routes->add($route);

        return $route;
    }

    private function createRoute(string $method, string $uri, $action): Route
    {
        // Si la acción es un array [Controller::class, 'method']
        if (\is_array($action) && isset($action[0]) && isset($action[1]) && \is_string($action[0]) && \is_string($action[1])) {
            // Convertir [Controller::class, 'method'] a formato interno
            $controller = $action[0];
            $methodName = $action[1];
            $action = [$controller, $methodName];
        }
        // Si la acción es un string, convertir a ['uses' => 'Controller@method']
        elseif (\is_string($action)) {
            if (str_contains($action, '@')) {
                [$controller, $methodName] = explode('@', $action);
                $action = ['uses' => $action, 'controller' => $controller];
            } else {
                $action = ['uses' => $action];
            }
        }
        // Si es un array asociativo con middleware u otras opciones
        elseif (\is_array($action) && isset($action['uses'])) {
            // Ya está en el formato correcto
        }

        $route = new Route($method, $uri, $action);

        // Extraer middleware del array si existe
        if (\is_array($action) && isset($action['middleware'])) {
            $route->middleware($action['middleware']);
        }

        return $route;
    }

    public function get(string $uri, $action): Route
    {
        return $this->addRoute('GET', $uri, $action);
    }

    public function post(string $uri, $action): Route
    {
        return $this->addRoute('POST', $uri, $action);
    }

    public function put(string $uri, $action): Route
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    public function patch(string $uri, $action): Route
    {
        return $this->addRoute('PATCH', $uri, $action);
    }

    public function delete(string $uri, $action): Route
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    public function any(string $uri, $action): Route
    {
        return $this->addRoute('ANY', $uri, $action);
    }

    public function match(array $methods, string $uri, $action): Route
    {
        $route = null;

        foreach ($methods as $method) {
            $route = $this->addRoute($method, $uri, $action);
        }

        return $route;
    }

    public function apiResource(string $name, $controller): void
    {
        $this->get($name, "{$controller}@index")->name("{$name}.index");
        $this->get("{$name}/{id}", "{$controller}@show")->name("{$name}.show");
        $this->post($name, "{$controller}@store")->name("{$name}.store");
        $this->put("{$name}/{id}", "{$controller}@update")->name("{$name}.update");
        $this->patch("{$name}/{id}", "{$controller}@update")->name("{$name}.update");
        $this->delete("{$name}/{id}", "{$controller}@destroy")->name("{$name}.destroy");
    }

    public function group(array $attributes, Closure $callback): void
    {
        $this->groupStack[] = $attributes;

        $callback($this);

        array_pop($this->groupStack);
    }

    private function mergeGroupAttributes(Route $route, array $attributes): Route
    {
        if (isset($attributes['prefix'])) {
            $prefix = $this->normalizeUri($attributes['prefix']);
            $uri = $route->uri;
            
            // Evitar barras dobles
            if ($prefix === '/' && $uri === '/') {
                $route->uri = '/';
            } elseif ($prefix === '/') {
                $route->uri = $uri;
            } elseif ($uri === '/') {
                $route->uri = $prefix;
            } else {
                $route->uri = $prefix . $uri;
            }
        }

        if (isset($attributes['namespace'])) {
            if (\is_array($route->action) && isset($route->action['uses'])) {
                $route->action['uses'] = $attributes['namespace'] . '\\' . $route->action['uses'];
            }
        }

        if (isset($attributes['middleware'])) {
            $route->middleware($attributes['middleware']);
        }

        if (isset($attributes['where'])) {
            $route->where($attributes['where']);
        }

        if (isset($attributes['domain'])) {
            $route->domain($attributes['domain']);
        }

        if (isset($attributes['as'])) {
            if ($route->name) {
                $route->name = $attributes['as'] . '.' . $route->name;
            } else {
                $route->name($attributes['as']);
            }
        }

        return $route;
    }

    private function normalizeUri(string $uri): string
    {
        return '/' . trim($uri, '/');
    }

    public function dispatch(Request $request): Response
    {
        $route = $this->routes->match($request->getMethod(), $request->getPath());

        if (!$route) {
            return Response::json([
                'error' => 'Not Found',
                'message' => 'Route not found',
                'path' => $request->getPath(),
                'method' => $request->getMethod()
            ], 404);
        }

        // Parsear parámetros
        $parameters = $route->parseParameters($request->getPath());

        // Combinar middleware global y de ruta
        $middleware = [...$this->globalMiddleware, ...$route->middleware];

        // Ejecutar la acción con middleware
        return $this->runRoute($request, $route, $parameters, $middleware);
    }

    private function runRoute(Request $request, Route $route, array $parameters, array $middleware)
    {
        // Registrar la request actual en el container
        $this->container->instance('request', $request);
        $this->container->instance(Request::class, $request);

        // Si hay middleware, crear pipeline
        if (!empty($middleware)) {
            $pipeline = new Pipeline($this->container, $middleware);

            return $pipeline->send($request)->then(fn() => $this->callAction($route, $parameters));
        }

        return $this->callAction($route, $parameters);
    }

    private function callAction(Route $route, array $parameters)
    {
        $action = $route->action;

        try {
            // Si es un Closure
            if ($action instanceof Closure) {
                return $this->container->call($action, $parameters);
            }

            // Si es un array [Controller::class, 'method']
            if (\is_array($action) && isset($action[0]) && isset($action[1]) && \is_string($action[0]) && \is_string($action[1])) {
                $controller = $action[0];
                $method = $action[1];
                
                // Resolver el controlador desde el container
                $controllerInstance = $this->container->make($controller);
                
                // Llamar al método del controlador
                return $this->container->call([$controllerInstance, $method], $parameters);
            }

            // Si es un array con 'uses'
            if (\is_array($action) && isset($action['uses'])) {
                return $this->container->call($action['uses'], $parameters);
            }

            // Si es un string (Controller@method)
            if (\is_string($action)) {
                return $this->container->call($action, $parameters);
            }

            throw new InvalidArgumentException('Invalid route action: ' . gettype($action));

        } catch (\Throwable $e) {
            return Response::json([
                'error' => 'Action Error',
                'message' => $e->getMessage(),
                'action' => \is_array($action) ? json_encode($action) : (\is_string($action) ? $action : gettype($action)),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function getRoutes(): array
    {
        return $this->routes->getRoutes();
    }
    
    public function getRouteCollection(): RouteCollection
    {
        return $this->routes;
    }

    public function url(string $name, array $parameters = []): string
    {
        $route = $this->routes->getByName($name);

        if (!$route) {
            throw new InvalidArgumentException("Route {$name} not found");
        }

        $uri = $route->uri;

        foreach ($parameters as $key => $value) {
            $uri = str_replace("{{$key}}", $value, $uri);
        }

        return $uri;
    }
}