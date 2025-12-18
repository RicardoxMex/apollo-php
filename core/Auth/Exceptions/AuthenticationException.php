<?php

namespace Apollo\Core\Auth\Exceptions;

use Exception;

class AuthenticationException extends Exception
{
    public function __construct(string $message = 'Authentication failed', int $code = 401, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}