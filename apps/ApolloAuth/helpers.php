<?php

use Apps\ApolloAuth\Facades\Auth;
use Apps\ApolloAuth\Models\User;
use Apps\ApolloAuth\Services\AuthService;

if (!function_exists('auth')) {
    /**
     * Get Auth instance or current user
     */
    function auth(?string $guard = null): AuthService
    {
        return app('auth');
    }
}

if (!function_exists('user')) {
    /**
     * Get current authenticated user
     */
    function user(): ?User
    {
        return Auth::user();
    }
}

if (!function_exists('user_id')) {
    /**
     * Get current user ID
     */
    function user_id(): ?int
    {
        return Auth::id();
    }
}

if (!function_exists('is_authenticated')) {
    /**
     * Check if user is authenticated
     */
    function is_authenticated(): bool
    {
        return Auth::check();
    }
}

if (!function_exists('is_guest')) {
    /**
     * Check if user is guest
     */
    function is_guest(): bool
    {
        return Auth::guest();
    }
}

if (!function_exists('has_role')) {
    /**
     * Check if current user has role
     */
    function has_role(string $role): bool
    {
        return Auth::hasRole($role);
    }
}

if (!function_exists('has_permission')) {
    /**
     * Check if current user has permission
     */
    function has_permission(string $permission): bool
    {
        return Auth::hasPermission($permission);
    }
}

if (!function_exists('is_admin')) {
    /**
     * Check if current user is admin
     */
    function is_admin(): bool
    {
        return Auth::isAdmin();
    }
}

if (!function_exists('now')) {
    /**
     * Get current timestamp
     */
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}