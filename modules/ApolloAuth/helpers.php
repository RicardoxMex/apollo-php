<?php

use ApolloAuth\Services\AuthService;
/*
if (!function_exists('auth')) {
    function auth(): AuthService
    {
        return app('auth.service');
    }
}

if (!function_exists('user')) {
    function user(): ?\ApolloAuth\Models\User
    {
        return auth()->user();
    }
}

if (!function_exists('can')) {
    function can(string $ability, $arguments = []): bool
    {
        $user = user();
        return $user ? $user->can($ability, $arguments) : false;
    }
}

if (!function_exists('has_role')) {
    function has_role(string $role): bool
    {
        $user = user();
        return $user ? $user->hasRole($role) : false;
    }
}

if (!function_exists('jwt')) {
    function jwt(): \ApolloAuth\Services\JwtService
    {
        return app('jwt.service');
    }
}

if (!function_exists('bcrypt')) {
    function bcrypt(string $password): string
    {
        return app('password.service')->hash($password);
    }
}

if (!function_exists('password_verify')) {
    function password_verify(string $password, string $hash): bool
    {
        return app('password.service')->verify($password, $hash);
    }
}
    */