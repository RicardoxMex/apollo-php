<?php
// apps/users/Routes/api.php

use Apollo\Core\Http\Response;

/** @var \Apollo\Core\Router\Router $router */

// Rutas públicas (sin middleware)
$router->get('/', fn() => Response::json([
    'data' => [
        ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'],
        ['id' => 2, 'name' => 'Jane Smith', 'email' => 'jane@example.com']
    ],
    'message' => 'Users retrieved successfully'
]))->name('users.index');

$router->get('/{id}', function($id) {
    return Response::json([
        'data' => [
            'id' => (int)$id,
            'name' => "User {$id}",
            'email' => "user{$id}@example.com"
        ],
        'message' => 'User retrieved successfully'
    ]);
})->where(['id' => '\d+'])->name('users.show');

// Ruta de prueba con logging
$router->get('/test', fn() => Response::json([
    'message' => 'Users API is working!',
    'timestamp' => date('Y-m-d H:i:s'),
    'version' => '1.0.0'
]))->middleware(['logging'])->name('users.test');

// Rutas que requieren autenticación
$router->group(['middleware' => ['auth']], function($router) {
    
    // Perfil del usuario autenticado
    $router->get('/profile', function() {
        $request = app('request'); // Obtener request del container
        $user = $request->attributes['user'] ?? null;
        
        return Response::json([
            'data' => $user,
            'message' => 'Profile retrieved successfully',
            'authenticated_at' => date('Y-m-d H:i:s')
        ]);
    })->name('users.profile');
    
    // Crear usuario (requiere autenticación)
    $router->post('/', function() {
        $request = app('request');
        $user = $request->attributes['user'] ?? null;
        
        return Response::json([
            'data' => [
                'id' => rand(100, 999),
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'created_by' => $user['name'] ?? 'Unknown'
            ],
            'message' => 'User created successfully'
        ], 201);
    })->name('users.store');
    
    // Actualizar usuario (requiere autenticación)
    $router->put('/{id}', function($id) {
        $request = app('request');
        $user = $request->attributes['user'] ?? null;
        
        return Response::json([
            'data' => [
                'id' => (int)$id,
                'name' => "Updated User {$id}",
                'email' => "updated{$id}@example.com",
                'updated_by' => $user['name'] ?? 'Unknown'
            ],
            'message' => 'User updated successfully'
        ]);
    })->where(['id' => '\d+'])->name('users.update');
});

// Rutas que requieren rol de administrador
$router->group(['middleware' => ['auth', 'role.admin']], function($router) {
    
    // Eliminar usuario (solo admin)
    $router->delete('/{id}', function($id) {
        $request = app('request');
        $user = $request->attributes['user'] ?? null;
        
        return Response::json([
            'message' => "User {$id} deleted successfully",
            'deleted_by' => $user['name'] ?? 'Unknown',
            'admin_action' => true
        ]);
    })->where(['id' => '\d+'])->name('users.destroy');
    
    // Estadísticas de usuarios (solo admin)
    $router->get('/stats', function() {
        $request = app('request');
        $user = $request->attributes['user'] ?? null;
        
        return Response::json([
            'data' => [
                'total_users' => 150,
                'active_users' => 120,
                'new_users_today' => 5,
                'admin_user' => $user['name'] ?? 'Unknown'
            ],
            'message' => 'User statistics retrieved successfully'
        ]);
    })->name('users.stats');
});

// Ruta de demostración con múltiples middlewares
$router->get('/demo', function() {
    $request = app('request');
    return Response::json([
        'message' => 'Demo endpoint with multiple middlewares',
        'middlewares_applied' => ['cors', 'logging', 'auth'],
        'user' => $request->attributes['user'] ?? null,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
})->middleware(['cors', 'logging', 'auth'])->name('users.demo');