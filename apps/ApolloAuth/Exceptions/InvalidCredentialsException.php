<?php

namespace Apps\ApolloAuth\Exceptions;

class InvalidCredentialsException extends AuthenticationException
{
    protected $message = 'Invalid credentials provided';
}