<?php

namespace Apps\ApolloAuth\Facades;

use Apps\ApolloAuth\Models\User;
use Apps\ApolloAuth\Services\AuthService;

/**
 * Auth Facade - Global access to authentication
 */
class Auth
{
    private static ?AuthService $instance = null;

    /**
     * Get AuthService instance
     */
    private static function getInstance(): AuthService
    {
        if (self::$instance === null) {
            self::$instance = app(AuthService::class);
        }
        return self::$instance;
    }

    /**
     * Attempt to authenticate user
     */
    public static function attempt(array $credentials, bool $remember = false): array
    {
        return self::getInstance()->attempt($credentials, $remember);
    }

    /**
     * Authenticate user from token
     */
    public static function authenticateFromToken(string $token): ?User
    {
        return self::getInstance()->authenticateFromToken($token);
    }

    /**
     * Get current authenticated user
     */
    public static function user(): ?User
    {
        return self::getInstance()->user();
    }

    /**
     * Get current token
     */
    public static function token(): ?string
    {
        return self::getInstance()->token();
    }

    /**
     * Check if user is authenticated
     */
    public static function check(): bool
    {
        return self::getInstance()->check();
    }

    /**
     * Check if user is guest
     */
    public static function guest(): bool
    {
        return self::getInstance()->guest();
    }

    /**
     * Logout current user
     */
    public static function logout(): bool
    {
        return self::getInstance()->logout();
    }

    /**
     * Logout from all devices
     */
    public static function logoutFromAllDevices(): bool
    {
        return self::getInstance()->logoutFromAllDevices();
    }

    /**
     * Refresh token
     */
    public static function refresh(): ?array
    {
        return self::getInstance()->refresh();
    }

    /**
     * Set authenticated user manually
     */
    public static function setUser(User $user): void
    {
        self::getInstance()->setUser($user);
    }

    /**
     * Get user ID
     */
    public static function id(): ?int
    {
        $user = self::user();
        return $user ? $user->id : null;
    }

    /**
     * Check if current user has role
     */
    public static function hasRole(string $role): bool
    {
        $user = self::user();
        return $user ? $user->hasRole($role) : false;
    }

    /**
     * Check if current user has permission
     */
    public static function hasPermission(string $permission): bool
    {
        $user = self::user();
        return $user ? $user->hasPermission($permission) : false;
    }

    /**
     * Check if current user is admin
     */
    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user ? $user->isAdmin() : false;
    }
}