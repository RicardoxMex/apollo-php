<?php
// core/Router/RouteCollection.php

namespace Apollo\Core\Router;

class RouteCollection {
    private array $routes = [];
    private array $namedRoutes = [];
    

    
    public function add(Route $route): void {
        $this->routes[] = $route;
        
        if ($route->name) {
            $this->namedRoutes[$route->name] = $route;
        }
    }
    
    public function match(string $method, string $uri): ?Route {
        foreach ($this->routes as $route) {
            if ($route->matches($method, $uri)) {
                return $route;
            }
        }
        
        return null;
    }
    
    public function getByName(string $name): ?Route {
        return $this->namedRoutes[$name] ?? null;
    }
    
    public function getRoutes(): array {
        return $this->routes;
    }
    
    public function clear(): void {
        $this->routes = [];
        $this->namedRoutes = [];
    }
    
    public function rebuildNamedRoutes(): void {
        $this->namedRoutes = [];
        foreach ($this->routes as $route) {
            if ($route->name) {
                $this->namedRoutes[$route->name] = $route;
            }
        }
    }
}