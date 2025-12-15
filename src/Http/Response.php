<?php

namespace ApolloPHP\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class Response implements ResponseInterface
{
    protected string $protocolVersion = '1.1';
    protected array $headers = [];
    protected StreamInterface $body;
    protected int $statusCode = 200;
    protected string $reasonPhrase = '';
    protected array $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];
    
    public function __construct(
        $body = null,
        int $status = 200,
        array $headers = []
    ) {
        $this->body = $body instanceof StreamInterface ? $body : new Stream($body ?? '');
        $this->statusCode = $status;
        $this->headers = $this->normalizeHeaders($headers);
        $this->reasonPhrase = $this->statusTexts[$status] ?? '';
    }
    
    public static function json($data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json';
        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return new self($body, $status, $headers);
    }
    
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    public function withStatus($code, $reasonPhrase = ''): self
    {
        $new = clone $this;
        $new->statusCode = (int) $code;
        $new->reasonPhrase = $reasonPhrase ?: ($this->statusTexts[$code] ?? '');
        return $new;
    }
    
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
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
    

    
    public function setStatusCode(int $code): self
    {
        return $this->withStatus($code);
    }
    
    public function withJson($data, int $status = 200): self
    {
        $response = static::json($data, $status);
        
        foreach ($this->headers as $name => $values) {
            if (strtolower($name) !== 'content-type') {
                foreach ($values as $value) {
                    $response = $response->withAddedHeader($name, $value);
                }
            }
        }
        
        return $response;
    }
    
    public function send(): void
    {
        if (!headers_sent()) {
            // Estatus
            header(\sprintf(
                'HTTP/%s %s %s',
                $this->protocolVersion,
                $this->statusCode,
                $this->reasonPhrase
            ));
            
            // Headers
            foreach ($this->headers as $name => $values) {
                $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
                foreach ($values as $value) {
                    header("$name: $value", false);
                }
            }
        }
        
        // Body
        echo $this->body;
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
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