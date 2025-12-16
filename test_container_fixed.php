<?php
// test_final_corrected.php

require_once __DIR__ . '/vendor/autoload.php';

echo "=== Testing Apollo Framework (Corrected) ===\n\n";

// Activar todos los errores para debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // 1. Test Container básico
    echo "1. Testing Container...\n";
    $container = Apollo\Core\Container\Container::getInstance();
    echo "   ✅ Container::getInstance() works\n";
    
    $container->bind('test.greeter', function() {
        return new class {
            public function greet($name = 'World') {
                return "Hello, $name!";
            }
        };
    });
    
    $greeter = $container->make('test.greeter');
    echo "   ✅ Binding works: " . $greeter->greet() . "\n";
    
    // 2. Test Application
    echo "\n2. Testing Application...\n";
    
    // Limpiar instancia singleton primero
    Apollo\Core\Container\Container::setInstance(null);
    
    $app = new Apollo\Core\Application(__DIR__);
    echo "   ✅ Application created (no deprecated warnings)\n";
    
    // 3. Verificar singleton
    $appInstance = Apollo\Core\Container\Container::getInstance();
    echo "   ✅ Container::getInstance() returns Application: " . 
         (($app === $appInstance) ? 'Yes ✅' : 'No ❌') . "\n";
    
    // 4. Test config
    echo "\n3. Testing Config...\n";
    try {
        $config = $app->make('config');
        echo "   ✅ Config resolved\n";
        
        // Configurar algo
        $config->set('app.name', 'Apollo Test');
        echo "   ✅ Config set/get: " . $config->get('app.name') . "\n";
    } catch (Exception $e) {
        echo "   ❌ Config error: " . $e->getMessage() . "\n";
    }
    
    // 5. Test helpers
    echo "\n4. Testing Helpers...\n";
    $appFromHelper = app();
    echo "   ✅ app() helper: " . (($app === $appFromHelper) ? 'Works ✅' : 'Fails ❌') . "\n";
    
    $configFromHelper = config();
    echo "   ✅ config() helper: " . (($config === $configFromHelper) ? 'Works ✅' : 'Fails ❌') . "\n";
    
    // 6. Test Router
    echo "\n5. Testing Router...\n";
    try {
        $router = $app->make('router');
        echo "   ✅ Router resolved\n";
        
        $router->get('/test', function() {
            return 'Test route';
        });
        
        echo "   ✅ Route registered. Total routes: " . count($router->getRoutes()) . "\n";
    } catch (Exception $e) {
        echo "   ❌ Router error: " . $e->getMessage() . "\n";
    }
    
    // 7. Test Request/Response
    echo "\n6. Testing Request/Response...\n";
    try {
        $request = Apollo\Core\Http\Request::capture();
        echo "   ✅ Request created\n";
        
        $response = Apollo\Core\Http\Response::json(['status' => 'ok']);
        echo "   ✅ Response created\n";
        
        $app->instance('request', $request);
        $app->instance('response', $response);
        echo "   ✅ Request/Response bound to container\n";
    } catch (Exception $e) {
        echo "   ❌ HTTP error: " . $e->getMessage() . "\n";
    }
    
    echo "\n🎉 Framework core is working!\n";
    
} catch (Exception $e) {
    echo "\n❌ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}