<?php

namespace Apps\ApolloAuth\Exceptions;

use Apollo\Core\Auth\Exceptions\AuthenticationException;

class InvalidCredentialsException extends AuthenticationException
{
    protected $message = 'Invalid credentials provided';
}