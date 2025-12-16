<?php
// core/Http/Request.php

namespace Apollo\Core\Http;

class Request {
    private array $query;
    private array $request;
    private array $attributes;
    private array $cookies;
    private array $files;
    private array $server;
    private ?string $content;
    
    public function __construct(
        array $query = [],
        array $request = [],
        array $attributes = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null
    ) {
        $this->query = $query;
        $this->request = $request;
        $this->attributes = $attributes;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
        $this->content = $content;
    }
    
    public static function capture(): self {
        return new self(
            $_GET,
            $_POST,
            [],
            $_COOKIE,
            $_FILES,
            $_SERVER,
            file_get_contents('php://input')
        );
    }
    
    public function getMethod(): string {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }
    
    public function getPath(): string {
        $path = $this->server['REQUEST_URI'] ?? '/';
        
        // Remover query string
        if (false !== $pos = strpos($path, '?')) {
            $path = substr($path, 0, $pos);
        }
        
        return rawurldecode($path);
    }
    
    public function getUri(): string {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->server['HTTP_HOST'] ?? 'localhost';
        
        return $scheme . '://' . $host . $this->getPath();
    }
    
    public function isSecure(): bool {
        $https = $this->server['HTTPS'] ?? '';
        return !empty($https) && $https !== 'off';
    }
    
    public function get(string $key, $default = null) {
        return $this->query[$key] ?? $this->request[$key] ?? $default;
    }
    
    public function input(string $key, $default = null) {
        return $this->request[$key] ?? $default;
    }
    
    public function query(string $key, $default = null) {
        return $this->query[$key] ?? $default;
    }
    
    public function all(): array {
        return array_merge($this->query, $this->request);
    }
    
    public function header(string $key, $default = null) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $this->server[$key] ?? $default;
    }
    
    public function json(string $key = null, $default = null) {
        $content = $this->getContent();
        
        if (empty($content)) {
            return $default;
        }
        
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }
        
        if ($key === null) {
            return $data;
        }
        
        return $data[$key] ?? $default;
    }
    
    public function getContent(): ?string {
        return $this->content;
    }
    
    public function isJson(): bool {
        $contentType = $this->server['CONTENT_TYPE'] ?? '';
        return stripos($contentType, 'application/json') !== false;
    }
    
    public function wantsJson(): bool {
        $accept = $this->server['HTTP_ACCEPT'] ?? '';
        return stripos($accept, 'application/json') !== false;
    }
    
    public function isMethod(string $method): bool {
        return $this->getMethod() === strtoupper($method);
    }
}