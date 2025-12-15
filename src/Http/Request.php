<?php

namespace ApolloPHP\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;

class Request implements RequestInterface
{
    protected string $method;
    protected Uri $uri;
    protected array $headers = [];
    protected StreamInterface $body;
    protected string $protocolVersion = '1.1';
    protected ?string $requestTarget = null;
    protected array $server;
    protected array $query;
    protected array $post;
    protected array $cookies;
    protected array $files;
    protected array $attributes = [];
    
    public function __construct(
        string $method,
        $uri,
        array $headers = [],
        $body = null,
        string $protocolVersion = '1.1'
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri instanceof Uri ? $uri : new Uri($uri);
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body instanceof StreamInterface ? $body : new Stream($body);
        $this->protocolVersion = $protocolVersion;
    }
    
    public static function createFromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = Uri::createFromGlobals();
        
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            } elseif (\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $name = str_replace('_', '-', strtolower($key));
                $headers[$name] = $value;
            }
        }
        
        $body = new Stream(fopen('php://input', 'r'));
        
        $request = new self($method, $uri, $headers, $body);
        
        // Almacenar datos globales
        $request->server = $_SERVER;
        $request->query = $_GET;
        $request->post = $_POST;
        $request->cookies = $_COOKIE;
        $request->files = $_FILES;
        
        // Parse JSON body
        if ($request->getHeaderLine('Content-Type') === 'application/json') {
            $content = $request->getBody()->getContents();
            if ($content) {
                $json = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->post = [...$request->post, ...$json];
                }
            }
        }
        
        return $request;
    }
    
    public function getMethod(): string
    {
        return $this->method;
    }
    
    public function withMethod($method): self
    {
        $new = clone $this;
        $new->method = strtoupper($method);
        return $new;
    }
    
    public function getUri(): Uri
    {
        return $this->uri;
    }
    
    public function withUri(UriInterface $uri, $preserveHost = false): self
    {
        $new = clone $this;
        $new->uri = $uri;
        
        if (!$preserveHost) {
            if ($uri->getHost() !== '') {
                $new->headers['host'] = [$uri->getHost()];
            }
        }
        
        return $new;
    }
    
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }
        
        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }
        
        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }
        
        return $target;
    }
    
    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $new = clone $this;
        $new->requestTarget = $requestTarget;
        return $new;
    }
    
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }
    
    public function withProtocolVersion($version): self
    {
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }
    
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    public function hasHeader($name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }
    
    public function getHeader($name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
    
    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }
    
    public function withHeader($name, $value): self
    {
        $new = clone $this;
        $new->headers[strtolower($name)] = \is_array($value) ? $value : [$value];
        return $new;
    }
    
    public function withAddedHeader($name, $value): self
    {
        $new = clone $this;
        $name = strtolower($name);
        $new->headers[$name] = [...$this->getHeader($name), ...(\is_array($value) ? $value : [$value])];
        return $new;
    }
    
    public function withoutHeader($name): self
    {
        $new = clone $this;
        unset($new->headers[strtolower($name)]);
        return $new;
    }
    
    public function getBody(): StreamInterface
    {
        return $this->body;
    }
    
    public function withBody(StreamInterface $body): self
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }
    
    public function getPath(): string
    {
        return $this->uri->getPath();
    }
    
    public function getQueryParams(): array
    {
        return $this->query;
    }
    
    public function getQueryParam(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }
    
    public function getPostParams(): array
    {
        return $this->post;
    }
    
    public function getPostParam(string $key, $default = null)
    {
        return $this->post[$key] ?? $default;
    }
    
    public function input(string $key = null, $default = null)
    {
        $data = [...$this->query, ...$this->post];
        
        if ($key === null) {
            return $data;
        }
        
        return $data[$key] ?? $default;
    }
    
    public function all(): array
    {
        return [...$this->query, ...$this->post];
    }
    
    public function only(array $keys): array
    {
        $data = $this->all();
        return array_intersect_key($data, array_flip($keys));
    }
    
    public function except(array $keys): array
    {
        $data = $this->all();
        return array_diff_key($data, array_flip($keys));
    }
    
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->all());
    }
    
    public function filled(string $key): bool
    {
        $value = $this->input($key);
        return !empty($value) || $value === '0';
    }
    
    public function bearerToken(): ?string
    {
        $header = $this->getHeaderLine('Authorization');
        
        if (strpos($header, 'Bearer ') === 0) {
            return substr($header, 7);
        }
        
        return null;
    }
    
    public function isJson(): bool
    {
        $contentType = $this->getHeaderLine('Content-Type');
        return strpos($contentType, 'application/json') !== false ||
               strpos($contentType, '+json') !== false;
    }
    
    public function expectsJson(): bool
    {
        $acceptable = $this->getHeaderLine('Accept');
        return strpos($acceptable, 'application/json') !== false ||
               strpos($acceptable, '+json') !== false;
    }
    
    public function wantsJson(): bool
    {
        return $this->expectsJson();
    }
    
    public function setAttribute(string $name, $value): void
    {
        $this->attributes[$name] = $value;
    }
    
    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }
    
    public function getAttributes(): array
    {
        return $this->attributes;
    }
    
    public function withAttribute($name, $value): self
    {
        $new = clone $this;
        $new->attributes[$name] = $value;
        return $new;
    }
    
    public function withoutAttribute($name): self
    {
        $new = clone $this;
        unset($new->attributes[$name]);
        return $new;
    }
    
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        
        foreach ($headers as $name => $value) {
            $name = strtolower($name);
            $normalized[$name] = \is_array($value) ? $value : [$value];
        }
        
        return $normalized;
    }
}