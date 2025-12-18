<?php

// Rutas públicas de autenticación
$router->post('/login', 'AuthController@login');
$router->post('/register', 'AuthController@register');

// Rutas protegidas de autenticación
$router->group(['middleware' => 'auth'], function ($router) {
    $router->get('/profile', 'AuthController@profile');
    $router->post('/logout', 'AuthController@logout');
    $router->post('/logout-all', 'AuthController@logoutAll');
    $router->post('/refresh', 'AuthController@refresh');
    $router->get('/sessions', 'AuthController@sessions');
});