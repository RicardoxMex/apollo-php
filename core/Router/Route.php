<?php
// core/Router/Route.php

namespace Apollo\Core\Router;

class Route {
    public string $method;
    public string $uri;
    public $action;
    public array $middleware = [];
    public array $where = [];
    public ?string $name = null;
    public ?string $domain = null;
    public array $defaults = [];
    
    public function __construct(string $method, string $uri, $action) {
        $this->method = strtoupper($method);
        $this->uri = $this->normalizeUri($uri);
        $this->action = $action;
    }
    
    private function normalizeUri(string $uri): string {
        return '/' . trim($uri, '/');
    }
    
    public function middleware($middleware): self {
        $this->middleware = array_merge(
            $this->middleware,
            is_array($middleware) ? $middleware : [$middleware]
        );
        return $this;
    }
    
    public function where(array $where): self {
        $this->where = array_merge($this->where, $where);
        return $this;
    }
    
    public function name(string $name): self {
        $this->name = $name;
        return $this;
    }
    
    public function domain(string $domain): self {
        $this->domain = $domain;
        return $this;
    }
    
    public function defaults(array $defaults): self {
        $this->defaults = array_merge($this->defaults, $defaults);
        return $this;
    }
    
    public function getRegex(): string {
        $pattern = $this->uri;
        
        // Reemplazar parámetros {param} por regex
        $pattern = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', function($matches) {
            $param = $matches[1];
            $regex = $matches[2] ?? ($this->where[$param] ?? '[^/]+');
            return "(?P<{$param}>{$regex})";
        }, $pattern);
        
        return '#^' . $pattern . '$#';
    }
    
    public function matches(string $method, string $uri): bool {
        if ($this->method !== $method && $this->method !== 'ANY') {
            return false;
        }
        
        return preg_match($this->getRegex(), $uri) === 1;
    }
    
    public function parseParameters(string $uri): array {
        preg_match($this->getRegex(), $uri, $matches);
        
        $params = [];
        foreach ($this->where as $param => $regex) {
            if (isset($matches[$param])) {
                $params[$param] = $matches[$param];
            }
        }
        
        return array_merge($this->defaults, $params);
    }
}