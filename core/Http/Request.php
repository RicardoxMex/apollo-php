<?php
// core/Http/Request.php

namespace Apollo\Core\Http;

use Apollo\Core\Auth\Models\User;

class Request
{
    private array $query;
    private array $request;
    public array $attributes;
    private array $cookies;
    private array $files;
    private array $server;
    private ?string $content;
    private ?User $user = null;

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

    public static function capture(): self
    {
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

    public function getMethod(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function getPath(): string
    {
        $path = $this->server['REQUEST_URI'] ?? '/';

        // Remover query string
        if (false !== $pos = strpos($path, '?')) {
            $path = substr($path, 0, $pos);
        }

        return rawurldecode($path);
    }

    public function getUri(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->server['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . $this->getPath();
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return !empty($https) && $https !== 'off';
    }

    public function get(string $key, $default = null)
    {
        return $this->query[$key] ?? $this->request[$key] ?? $default;
    }

    public function input(string $key, $default = null)
    {
        return $this->request[$key] ?? $default;
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->request);
    }

    public function header(string $key, $default = null)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $this->server[$key] ?? $default;
    }

    public function json(?string $key = null, $default = null)
    {
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

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function isJson(): bool
    {
        $contentType = $this->server['CONTENT_TYPE'] ?? '';
        return stripos($contentType, 'application/json') !== false;
    }

    public function wantsJson(): bool
    {
        $accept = $this->server['HTTP_ACCEPT'] ?? '';
        return stripos($accept, 'application/json') !== false;
    }

    public function isMethod(string $method): bool
    {
        return $this->getMethod() === strtoupper($method);
    }
    
    public function ip(): string
    {
        // Verificar headers de proxy primero
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED'
        ];
        
        foreach ($headers as $header) {
            if (!empty($this->server[$header])) {
                $ips = explode(',', $this->server[$header]);
                $ip = trim($ips[0]);
                
                // Validar que sea una IP válida
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        // Fallback a REMOTE_ADDR
        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Get user agent
     */
    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Set authenticated user
     */
    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    /**
     * Get authenticated user
     */
    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * Check if request has authenticated user
     */
    public function hasUser(): bool
    {
        return $this->user !== null;
    }
}