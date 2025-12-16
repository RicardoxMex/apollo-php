<?php

/** @var \ApolloPHP\Core\Router $router */



// Rutas públicas de autenticación
$router->post('/login', [\ApolloAuth\Controllers\AuthController::class, 'login']);
$router->post('/register', [\ApolloAuth\Controllers\AuthController::class, 'register']);
$router->post('/refresh', [\ApolloAuth\Controllers\AuthController::class, 'refresh']);
$router->post('/forgot-password', [\ApolloAuth\Controllers\AuthController::class, 'forgotPassword']);
$router->post('/reset-password', [\ApolloAuth\Controllers\AuthController::class, 'resetPassword']);

// Rutas protegidas
$router->group(['middleware' => ['auth']], function ($router) {
    // Perfil del usuario
    $router->get('/me', [\ApolloAuth\Controllers\AuthController::class, 'me']);
    $router->post('/logout', [\ApolloAuth\Controllers\AuthController::class, 'logout']);
    $router->put('/me', [\ApolloAuth\Controllers\AuthController::class, 'updateProfile']);
    $router->put('/password', [\ApolloAuth\Controllers\AuthController::class, 'changePassword']);
});
