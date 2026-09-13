<?php

use Apps\Notifications\Controllers\NotificationsController;

/** @var \Apollo\Core\Router\Router $router */

// Notificaciones del usuario autenticado (prefix: api/notifications)
$router->group(['middleware' => ['auth', 'cors']], function ($router) {
    $router->get('/', [NotificationsController::class, 'index'])->name('notifications.index');
    $router->get('/{id}', [NotificationsController::class, 'show'])->where(['id' => '[a-zA-Z0-9_]+'])->name('notifications.show');
    $router->post('/{id}/read', [NotificationsController::class, 'markAsRead'])->where(['id' => '[a-zA-Z0-9_]+'])->name('notifications.read');
    $router->post('/read-all', [NotificationsController::class, 'markAllAsRead'])->name('notifications.read_all');
});