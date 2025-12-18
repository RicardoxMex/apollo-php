<?php

namespace Apps\ApolloAuth\Exceptions;

class UserNotActiveException extends AuthenticationException
{
    protected $message = 'User account is not active';
}