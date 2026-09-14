<?php

// Rutas públicas de autenticación (con rate limit por IP contra fuerza bruta)
$router->post('/login', 'AuthController@login')->middleware(['cors']);
$router->post('/register', 'AuthController@register')->middleware(['cors']);

// Verificación de email: pública (token del enlace), reenvío autenticado
$router->post('/verify-email', 'AuthController@verifyEmail')->middleware(['cors',]);
$router->post('/resend-verification', 'AuthController@resendVerification')->middleware(['auth', 'cors',]);

// Password reset (D3): públicos con rate limit; forgot nunca revela la existencia del email
$router->post('/forgot-password', 'AuthController@forgotPassword')->middleware(['cors',]);
$router->post('/reset-password', 'AuthController@resetPassword')->middleware(['cors',]);

// Rutas protegidas de autenticación
$router->group(['middleware' => ['auth', 'cors']], function ($router) {
    $router->get('/profile', 'AuthController@profile');
    $router->put('/profile', 'AuthController@updateProfile');
    $router->post('/logout', 'AuthController@logout');
    $router->post('/logout-all', 'AuthController@logoutAll');
    $router->post('/refresh', 'AuthController@refresh');
    $router->get('/sessions', 'AuthController@sessions');
});