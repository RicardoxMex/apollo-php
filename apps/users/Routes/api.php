<?php
// apps/users/Routes/api.php

use Apollo\Core\Http\Response;
use Apps\Users\Controllers\UserController;

/** @var \Apollo\Core\Router\Router $router */

// Rutas públicas (sin middleware)
$router->get('/', [UserController::class, 'index'])->name('users.index');

// Ejemplo con sintaxis [Controller::class, 'method']
$router->get('/{id}', [UserController::class, 'show'])->where(['id' => '\d+'])->name('users.show');

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
    
    // Crear usuario (requiere autenticación) - Usando sintaxis [Controller::class, 'method']
    $router->post('/', [UserController::class, 'store'])->name('users.store');
    
    // Actualizar usuario (requiere autenticación) - Usando sintaxis [Controller::class, 'method']
    $router->put('/{id}', [UserController::class, 'update'])->where(['id' => '\d+'])->name('users.update');
});

// Rutas que requieren rol de administrador
$router->group(['middleware' => ['auth', 'role.admin']], function($router) {
    
    // Eliminar usuario (solo admin) - Usando sintaxis [Controller::class, 'method']
    $router->delete('/{id}', [UserController::class, 'destroy'])->where(['id' => '\d+'])->name('users.destroy');
    
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