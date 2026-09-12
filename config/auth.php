<?php
return [
    'defaults' => [
        'guard' => 'api',
    ],

    'guards' => [
        'api' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => \Apps\ApolloAuth\Models\User::class,
        ],
    ],

    'jwt' => [
        'secret_key' => env('JWT_SECRET_KEY'),
        'algorithm' => env('JWT_ALGORITHM', 'HS256'),
        'issuer' => env('JWT_ISSUER', 'apollo-api.local'),
        'audience' => env('JWT_AUDIENCE', 'apollo-client'),
        'expiry' => (int) env('JWT_EXPIRY', 86400), // 24 horas (sesión de trabajo larga)
        'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 20160), // 2 semanas
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_resets',
            'expire' => 60, // minutos
        ],
    ],

    'rate_limit' => [
        'max_attempts' => (int) env('RATE_LIMIT_MAX_ATTEMPTS', 5),
        'window' => (int) env('RATE_LIMIT_WINDOW', 900), // 15 minutos
        'lockout_duration' => (int) env('RATE_LIMIT_LOCKOUT', 900), // 15 minutos
    ],

    'session' => [
        'cleanup_expired' => true,
        'cleanup_interval' => 3600, // 1 hora
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles & Permissions (módulo de acceso del core)
    |--------------------------------------------------------------------------
    |
    | Habilita el trait HasRoles, RoleMiddleware y PermissionMiddleware de
    | Apollo\Core\Auth (gates 'role.admin' / 'role.user'). Si está desactivado,
    | no se registra ningún alias y el módulo queda inerte (las tablas roles /
    | user_roles no son necesarias).
    |
    */
    'access' => [
        'enabled' => env('AUTH_ACCESS_ENABLED', true),
        'role_model' => \Apollo\Core\Auth\Models\Role::class,
        'permission_model' => \Apollo\Core\Auth\Models\Permission::class,
    ],
];