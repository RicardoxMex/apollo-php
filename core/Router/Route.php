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
    private array $parameterNames = [];
    
    public function __construct(string $method, string $uri, $action) {
        $this->method = strtoupper($method);
        $this->uri = $this->normalizeUri($uri);
        $this->action = $action;
        $this->extractParameterNames();
    }
    
    private function normalizeUri(string $uri): string {
        if ($uri === '/') {
            return '/';
        }
        return '/' . trim($uri, '/');
    }
    
    private function extractParameterNames(): void {
        preg_match_all('/\{(\w+)(?::([^}]+))?\}/', $this->uri, $matches);
        $this->parameterNames = $matches[1] ?? [];
    }
    
    public function middleware($middleware): self {
        $middlewareArray = \is_array($middleware) ? $middleware : [$middleware];
        $this->middleware = [...$this->middleware, ...$middlewareArray];
        return $this;
    }
    
    public function where(array $where): self {
        $this->where = [...$this->where, ...$where];
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
        $this->defaults = [...$this->defaults, ...$defaults];
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
        
        return "#^{$pattern}$#";
    }
    
    public function matches(string $method, string $uri): bool {
        // Normalizar método
        $method = strtoupper($method);
        
        if ($this->method !== $method && $this->method !== 'ANY') {
            return false;
        }
        
        return preg_match($this->getRegex(), $uri) === 1;
    }
    
    public function parseParameters(string $uri): array {
        $matches = [];
        preg_match($this->getRegex(), $uri, $matches);
        
        $params = [];
        
        // Extraer parámetros nombrados del regex
        foreach ($this->parameterNames as $paramName) {
            if (isset($matches[$paramName])) {
                $params[$paramName] = $matches[$paramName];
            }
        }
        
        return [...$this->defaults, ...$params];
    }
    
    public function getParameterNames(): array {
        return $this->parameterNames;
    }
    
    public function hasParameters(): bool {
        return !empty($this->parameterNames);
    }
}