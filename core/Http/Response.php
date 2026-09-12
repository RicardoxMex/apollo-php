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
    
    /**
     * Servir un archivo del filesystem como descarga (Content-Disposition: attachment).
     * Si el archivo no existe devuelve un 404 JSON.
     */
    public static function download(string $path, ?string $name = null, array $headers = []): self {
        $name = $name ?? basename($path);
        
        return self::serveFile($path, $name, 'attachment', $headers);
    }
    
    /**
     * Servir un archivo inline (se muestra/abre en el navegador).
     */
    public static function file(string $path, array $headers = []): self {
        return self::serveFile($path, basename($path), 'inline', $headers);
    }
    
    private static function serveFile(string $path, string $name, string $disposition, array $headers = []): self {
        if (!is_file($path)) {
            return self::json([
                'error' => 'Not Found',
                'message' => 'File not found',
            ], 404);
        }
        
        $mime = 'application/octet-stream';
        
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                
                if ($detected !== false && $detected !== '') {
                    $mime = $detected;
                }
            }
        }
        
        $headers['Content-Type'] = $mime;
        $headers['Content-Length'] = (string) filesize($path);
        $headers['Content-Disposition'] = $disposition . '; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"';
        
        $content = file_get_contents($path);
        
        if ($content === false) {
            return self::json([
                'error' => 'Internal Server Error',
                'message' => 'Could not read file',
            ], 500);
        }
        
        return new self($content, 200, $headers);
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
    
    public function withHeaders(array $headers): self {
        $this->headers = [...$this->headers, ...$headers];
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