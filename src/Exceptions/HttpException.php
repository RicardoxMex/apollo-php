<?php

namespace ApolloPHP\Exceptions;

use Exception;
use Throwable;

class HttpException extends Exception
{
    protected int $statusCode;
    protected array $headers;
    
    public function __construct(
        int $statusCode,
        string $message = '',
        array $headers = [],
        Throwable $previous = null,
        int $code = 0
    ) {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        
        parent::__construct($message, $code, $previous);
    }
    
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(404, $message);
    }
    
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(401, $message);
    }
    
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(403, $message);
    }
    
    public static function badRequest(string $message = 'Bad Request'): self
    {
        return new self(400, $message);
    }
}