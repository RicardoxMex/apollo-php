<?php
// core/Http/Request.php

namespace Apollo\Core\Http;

use Apollo\Core\Uploads\Support\UploadedFile;

class Request
{
    private array $query;
    private array $request;
    public array $attributes;
    private array $cookies;
    private array $files;
    private array $server;
    private ?string $content;
    private $user = null;

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

    /**
     * Archivo subido del campo indicado (UploadedFile|null).
     * Normaliza $_FILES (simple y multi-file) a la forma interna del framework.
     */
    public function file(string $key): ?UploadedFile
    {
        $files = $this->normalizeFiles($this->files);

        if (!isset($files[$key])) {
            return null;
        }

        $entry = $files[$key];

        // Multi-file con un solo elemento (file[0]): el primero
        if (is_array($entry) && array_is_list($entry)) {
            return UploadedFile::fromArray($entry[0] ?? []);
        }

        return UploadedFile::fromArray($entry);
    }

    /**
     * ¿El request trae un archivo válido en el campo indicado?
     */
    public function hasFile(string $key): bool
    {
        $file = $this->file($key);

        return $file !== null && $file->isValid();
    }

    /**
     * Archivos del campo indicado (array de UploadedFile), o todos los campos.
     *
     * @return array<string, UploadedFile>|UploadedFile[]|array
     */
    public function files(?string $key = null): array
    {
        $files = $this->normalizeFiles($this->files);

        if ($key !== null) {
            $entry = $files[$key] ?? [];

            if (is_array($entry) && array_is_list($entry)) {
                return array_values(array_filter(array_map(
                    fn ($item) => UploadedFile::fromArray($item),
                    $entry
                )));
            }

            $file = UploadedFile::fromArray($entry);

            return $file === null ? [] : [$file];
        }

        $result = [];

        foreach ($files as $field => $entry) {
            if (is_array($entry) && array_is_list($entry)) {
                foreach ($entry as $item) {
                    $result[] = UploadedFile::fromArray($item);
                }
            } else {
                $result[] = UploadedFile::fromArray($entry);
            }
        }

        return array_values(array_filter($result));
    }

    /**
     * Todos los archivos subidos como array de UploadedFile.
     *
     * @return UploadedFile[]
     */
    public function allFiles(): array
    {
        return $this->files();
    }

    /**
     * Normalizar $_FILES: las estructuras `name[name]` y `files[0]` a listas.
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if (!is_array($value) || !isset($value['name'])) {
                $normalized[$key] = $value;
                continue;
            }

            // name puede ser string (single) o array (multi: files[], files[0], files[n])
            if (is_array($value['name'])) {
                $entries = [];

                foreach (array_keys($value['name']) as $index) {
                    $entryName = $value['name'][$index];

                    // Estructura exótica files[n][name]: no la genera un input de archivo estándar
                    if (is_array($entryName)) {
                        continue;
                    }

                    $entries[] = [
                        'name' => $entryName,
                        'type' => $value['type'][$index] ?? null,
                        'tmp_name' => $value['tmp_name'][$index] ?? null,
                        'error' => $value['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $value['size'][$index] ?? 0,
                    ];
                }

                $normalized[$key] = $entries;
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
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
    public function setUser($user): void
    {
        $this->user = $user;
    }

    /**
     * Get authenticated user
     */
    public function user()
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