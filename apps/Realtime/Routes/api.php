<?php
// apps/Realtime/Routes/api.php
//
// App OPCIONAL del módulo realtime. Para activarla, añade 'Realtime' al
// array 'registered' de config/apps.php y configura REALTIME_APP_SECRET.
//
// Protección: los endpoints de escritura piden Authorization: Bearer <app_secret>
// (el cliente WebSocket SOLO recibe app_key, nunca el secreto).

use Apps\Realtime\Controllers\RealtimeApiController;

/** @var \Apollo\Core\Router\Router $router */

$router->post('/events', [RealtimeApiController::class, 'publish'])->name('realtime.events');
$router->post('/broadcast', [RealtimeApiController::class, 'publish'])->name('realtime.broadcast');
$router->post('/realtime/auth', [RealtimeApiController::class, 'authorize'])->name('realtime.auth');
$router->get('/channels', [RealtimeApiController::class, 'channels'])->name('realtime.channels');

// Notificaciones (requieren usuario autenticado JWT)
$router->group(['middleware' => ['auth']], function ($router) {
    $router->get('/notifications', [RealtimeApiController::class, 'notifications'])->name('realtime.notifications');
    $router->get('/notifications/{id}/read', [RealtimeApiController::class, 'markAsRead'])->name('realtime.notifications.read');
});