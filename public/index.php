<?php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

try {
    $app = new ApolloPHP\Core\Application(dirname(__DIR__));
    
    // Endpoint para debug - ver rutas registradas
    $app->get('/debug/routes', function($request) use ($app) {
        $router = $app->getRouter();
        $routes = $router->getRoutes();
        
        return new ApolloPHP\Http\JsonResponse([
            'message' => 'Registered routes',
            'routes' => $routes
        ]);
    });
    
    // Endpoint para debug - información del módulo
    $app->get('/debug/module', function($request) use ($app) {
        $modules = $app->getModules();
        $moduleInfo = [];
        
        foreach ($modules as $name => $module) {
            $moduleInfo[$name] = [
                'name' => $module->getName(),
                'path' => $module->getPath(),
            ];
        }
        
        return new ApolloPHP\Http\JsonResponse([
            'message' => 'Loaded modules',
            'modules' => $moduleInfo
        ]);
    });
    
    // Test endpoint
    $app->post('/test', function($request) {
        return new ApolloPHP\Http\JsonResponse(['message' => 'Test endpoint works']);
    });
    
    // Cargar módulo ApolloAuth
    $app->module('ApolloAuth');
    
    $app->run();
    
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}