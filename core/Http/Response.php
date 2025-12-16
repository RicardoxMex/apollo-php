<?php
// core/Http/Response.php

namespace Apollo\Core\Http;

class Response {
    private mixed $content;
    private int $status;
    private array $headers;
    
    public function __construct($content = '', int $status = 200, array $headers = []) {
        $this->content = $content;
        $this->status = $status;
        $this->headers = array_merge([
            'Content-Type' => 'application/json; charset=utf-8',
        ], $headers);
    }
    
    public static function json($data, int $status = 200, array $headers = []): self {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        
        return new self($content, $status, $headers);
    }
    
    public static function text(string $text, int $status = 200, array $headers = []): self {
        $headers['Content-Type'] = 'text/plain; charset=utf-8';
        return new self($text, $status, $headers);
    }
    
    public static function html(string $html, int $status = 200, array $headers = []): self {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new self($html, $status, $headers);
    }
    
    public static function redirect(string $url, int $status = 302): self {
        return new self('', $status, ['Location' => $url]);
    }
    
    public function setContent($content): self {
        $this->content = $content;
        return $this;
    }
    
    public function setStatusCode(int $status): self {
        $this->status = $status;
        return $this;
    }
    
    public function setHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }
    
    public function send(): void {
        http_response_code($this->status);
        
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        echo $this->content;
    }
    
    public function getContent(): mixed {
        return $this->content;
    }
    
    public function getStatusCode(): int {
        return $this->status;
    }
    
    public function getHeaders(): array {
        return $this->headers;
    }
}