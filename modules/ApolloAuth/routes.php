<?php

/** @var \ApolloPHP\Core\Router $router */

$router->get('/', function ($request, $response) {
    return response([
        'message' => 'Welcome to ApolloPHP!',
        'version' => '1.0.0',
        'author' => 'ApolloPHP Team',
        'repository' => 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'
    ]);
});

/*
// Rutas públicas de autenticación
$router->post('/login', [\ApolloAuth\Controllers\AuthController::class, 'login']);
$router->post('/register', [\ApolloAuth\Controllers\AuthController::class, 'register']);
$router->post('/refresh', [\ApolloAuth\Controllers\AuthController::class, 'refresh']);
$router->post('/forgot-password', [\ApolloAuth\Controllers\AuthController::class, 'forgotPassword']);
$router->post('/reset-password', [\ApolloAuth\Controllers\AuthController::class, 'resetPassword']);

// Rutas protegidas
$router->group(['middleware' => ['auth']], function($router) {
    // Perfil del usuario
    $router->get('/me', [\ApolloAuth\Controllers\AuthController::class, 'me']);
    $router->post('/logout', [\ApolloAuth\Controllers\AuthController::class, 'logout']);
    $router->put('/me', [\ApolloAuth\Controllers\AuthController::class, 'updateProfile']);
    $router->put('/password', [\ApolloAuth\Controllers\AuthController::class, 'changePassword']);
    
    // Gestión de usuarios (solo admin)
    $router->group(['middleware' => ['can:manage-users']], function($router) {
        $router->get('/users', [\ApolloAuth\Controllers\UserController::class, 'index']);
        $router->get('/users/{uuid}', [\ApolloAuth\Controllers\UserController::class, 'show']);
        $router->post('/users', [\ApolloAuth\Controllers\UserController::class, 'store']);
        $router->put('/users/{uuid}', [\ApolloAuth\Controllers\UserController::class, 'update']);
        $router->delete('/users/{uuid}', [\ApolloAuth\Controllers\UserController::class, 'destroy']);
        $router->put('/users/{uuid}/roles', [\ApolloAuth\Controllers\UserController::class, 'syncRoles']);
        $router->put('/users/{uuid}/status', [\ApolloAuth\Controllers\UserController::class, 'updateStatus']);
    });
    
    // Gestión de roles (solo admin)
    $router->group(['middleware' => ['can:manage-roles']], function($router) {
        $router->get('/roles', [\ApolloAuth\Controllers\RoleController::class, 'index']);
        $router->get('/roles/{id}', [\ApolloAuth\Controllers\RoleController::class, 'show']);
        $router->post('/roles', [\ApolloAuth\Controllers\RoleController::class, 'store']);
        $router->put('/roles/{id}', [\ApolloAuth\Controllers\RoleController::class, 'update']);
        $router->delete('/roles/{id}', [\ApolloAuth\Controllers\RoleController::class, 'destroy']);
        $router->put('/roles/{id}/permissions', [\ApolloAuth\Controllers\RoleController::class, 'syncPermissions']);
    });
});
*/