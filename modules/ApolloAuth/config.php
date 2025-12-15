<?php

return [
    'defaults' => [
        'guard' => 'api',
        'provider' => 'users',
    ],
    
    'guards' => [
        'api' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
    ],
    
    'providers' => [
        'users' => [
            'driver' => 'database',
            'model' => ApolloAuth\Models\User::class,
        ],
    ],
    
    'jwt' => [
        'secret' => env('JWT_SECRET', 'your-secret-key-change-this'),
        'ttl' => env('JWT_TTL', 3600),
        'refresh_ttl' => env('JWT_REFRESH_TTL', 86400),
        'algo' => 'HS256',
    ],
    
    'passwords' => [
        'expire' => 60,
        'throttle' => 60,
    ],
    
    'routes' => [
        'prefix' => 'api/auth',
        'middleware' => [],
    ],
    
    'policies' => [
       // \ApolloAuth\Models\User::class => \ApolloAuth\Policies\UserPolicy::class,
    ],
];