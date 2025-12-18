<?php

namespace Apollo\Core\Auth\Exceptions;

use Exception;

class UserNotActiveException extends Exception
{
    public function __construct(string $message = 'User account is not active', int $code = 403, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}