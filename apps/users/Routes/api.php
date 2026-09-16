<?php
// apps/Users/Routes/api.php

use Apollo\Core\Http\Response;
use Apps\Users\Controllers\UserController;

/** @var \Apollo\Core\Router\Router $router */

// Rutas que requieren rol de administrador (proyección segura: sin password)
$router->group(['middleware' => ['auth', 'role.admin']], function($router) {

    $router->get('/', [UserController::class, 'index'])->name('users.index');

    // Ejemplo con sintaxis [Controller::class, 'method']
    $router->get('/{id}', [UserController::class, 'show'])->where(['id' => '\d+'])->name('users.show');

    // Ruta de prueba con logging (antes pública; ahora solo admin)
    $router->get('/test', fn() => Response::json([
        'message' => 'Users API is working!',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0.0'
    ]))->middleware(['logging'])->name('users.test');

    // Crear usuario (solo admin)
    $router->post('/', [UserController::class, 'store'])->name('users.store');

    // Actualizar usuario (solo admin)
    $router->put('/{id}', [UserController::class, 'update'])->where(['id' => '\d+'])->name('users.update');

    // Eliminar usuario (solo admin) - Usando sintaxis [Controller::class, 'method']
    $router->delete('/{id}', [UserController::class, 'destroy'])->where(['id' => '\d+'])->name('users.destroy');

    // Estadísticas de usuarios (solo admin)
    $router->get('/stats', function() {
        $request = app('request');
        $user = $request->user();

        return Response::json([
            'data' => [
                'total_users' => 150,
                'active_users' => 120,
                'new_users_today' => 5,
                'admin_user' => $user->username ?? 'Unknown'
            ],
            'message' => 'User statistics retrieved successfully'
        ]);
    })->name('users.stats');

    // Ruta de demostración (antes pública con JWT; ahora solo admin)
    $router->get('/demo', function() {
        $request = app('request');
        $user = $request->user();

        return Response::json([
            'message' => 'Demo endpoint with multiple middlewares',
            'middlewares_applied' => ['cors', 'logging', 'auth'],
            'user' => $user ? [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email
            ] : null,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    })->middleware(['cors', 'logging'])->name('users.demo');
});

// Rutas que requieren autenticación (JWT), sin rol admin
$router->group(['middleware' => ['auth']], function($router) {

    // Perfil del usuario autenticado
    $router->get('/profile', function() {
        $request = app('request');
        $user = $request->user();

        if (!$user) {
            return Response::json([
                'data' => null,
                'message' => 'Profile retrieved successfully'
            ]);
        }

        return Response::json([
            'data' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'roles' => array_map(fn($role) => $role->name, $user->roles())
            ],
            'message' => 'Profile retrieved successfully'
        ]);
    })->name('users.profile');
});
