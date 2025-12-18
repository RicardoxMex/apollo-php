<?php

namespace Apps\ApolloAuth\Exceptions;

use Exception;

class AuthenticationException extends Exception
{
    protected $message = 'Authentication failed';
    protected $code = 401;
}