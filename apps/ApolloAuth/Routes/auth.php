<?php

// Rutas públicas de autenticación
$router->post('/login', 'AuthController@login')->middleware('cors');
$router->post('/register', 'AuthController@register')->middleware('cors');

// Rutas protegidas de autenticación
$router->group(['middleware' => ['auth', 'cors']], function ($router) {
    $router->get('/profile', 'AuthController@profile');
    $router->post('/logout', 'AuthController@logout');
    $router->post('/logout-all', 'AuthController@logoutAll');
    $router->post('/refresh', 'AuthController@refresh');
    $router->get('/sessions', 'AuthController@sessions');
});