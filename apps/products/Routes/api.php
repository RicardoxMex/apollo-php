<?php
// apps/Products/Routes/api.php
//
// Ejemplo de referencia del framework:
//   - Lecturas públicas (GET) sin middleware
//   - Escrituras protegidas con auth JWT real (middleware 'auth', registrado
//     en apps/ApolloAuth/ApolloAuthServiceProvider -> AuthMiddleware)
//   - Para exigir un rol, usa ['auth', 'role.admin'] (ver apps/Users/Routes/api.php)

use Apps\Products\Controllers\ProductController;

/** @var \Apollo\Core\Router\Router $router */

// --- Lecturas públicas ---
$router->get('/', [ProductController::class, 'index'])->name('products.index');
$router->get('/{id}', [ProductController::class, 'show'])->where(['id' => '\d+'])->name('products.show');

// --- Escrituras protegidas (requieren token JWT válido: Authorization: Bearer <token>) ---
$router->group(['middleware' => ['auth']], function($router) {
    $router->post('/', [ProductController::class, 'store'])->name('products.store');
    $router->put('/{id}', [ProductController::class, 'update'])->where(['id' => '\d+'])->name('products.update');
    $router->delete('/{id}', [ProductController::class, 'destroy'])->where(['id' => '\d+'])->name('products.destroy');
});