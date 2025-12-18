<?php

// Rutas de administración - requieren rol admin
$router->group(['middleware' => ['auth', 'role:admin']], function ($router) {
    // Gestión de usuarios
    $router->get('/admin/users', 'AdminController@users');
    $router->get('/admin/users/{id}', 'AdminController@showUser');
    $router->put('/admin/users/{id}', 'AdminController@updateUser');
    
    // Gestión de roles
    $router->get('/admin/roles', 'AdminController@roles');
    $router->post('/admin/users/{id}/roles', 'AdminController@assignRole');
    $router->delete('/admin/users/{id}/roles/{role}', 'AdminController@removeRole');
});