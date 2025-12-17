<?php
// test_middleware.php - Script para probar middlewares

require_once __DIR__ . '/vendor/autoload.php';

// Inicializar variables de entorno
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

use Apollo\Core\Application;
use Apollo\Core\Http\Request;

echo "🧪 Testing Middleware System\n";
echo "============================\n\n";

try {
    // Crear aplicación
    $app = new Application(__DIR__);
    
    // Registrar apps
    $app->registerApp('users');
    
    // Registrar providers del core
    $app->registerServiceProvider(new Apollo\Core\Providers\AppServiceProvider($app));
    
    // Boot service providers
    $app->bootServiceProviders();
    
    echo "✅ Application initialized with middlewares\n\n";
    
    // Casos de prueba
    $testCases = [
        [
            'name' => 'Ruta pública (sin middleware)',
            'method' => 'GET',
            'path' => '/api/users',
            'headers' => []
        ],
        [
            'name' => 'Ruta con logging middleware',
            'method' => 'GET',
            'path' => '/api/users/test',
            'headers' => []
        ],
        [
            'name' => 'Ruta protegida sin token',
            'method' => 'GET',
            'path' => '/api/users/profile',
            'headers' => []
        ],
        [
            'name' => 'Ruta protegida con token inválido',
            'method' => 'GET',
            'path' => '/api/users/profile',
            'headers' => ['Authorization' => 'Bearer invalid-token']
        ],
        [
            'name' => 'Ruta protegida con token válido',
            'method' => 'GET',
            'path' => '/api/users/profile',
            'headers' => ['Authorization' => 'Bearer test-token-123']
        ],
        [
            'name' => 'Crear usuario (autenticado)',
            'method' => 'POST',
            'path' => '/api/users',
            'headers' => ['Authorization' => 'Bearer user-token-456']
        ],
        [
            'name' => 'Eliminar usuario (requiere admin)',
            'method' => 'DELETE',
            'path' => '/api/users/123',
            'headers' => ['Authorization' => 'Bearer user-token-456'] // usuario normal
        ],
        [
            'name' => 'Eliminar usuario (con admin)',
            'method' => 'DELETE',
            'path' => '/api/users/123',
            'headers' => ['Authorization' => 'Bearer test-token-123'] // admin
        ],
        [
            'name' => 'Estadísticas (solo admin)',
            'method' => 'GET',
            'path' => '/api/users/stats',
            'headers' => ['Authorization' => 'Bearer test-token-123']
        ],
        [
            'name' => 'Demo con múltiples middlewares',
            'method' => 'GET',
            'path' => '/api/users/demo',
            'headers' => [
                'Authorization' => 'Bearer demo-token-789',
                'Origin' => 'https://example.com',
                'User-Agent' => 'Test Client 1.0'
            ]
        ]
    ];
    
    foreach ($testCases as $i => $testCase) {
        echo "🎯 Test " . ($i + 1) . ": {$testCase['name']}\n";
        echo "   {$testCase['method']} {$testCase['path']}\n";
        
        // Configurar headers
        foreach ($testCase['headers'] as $header => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] = $value;
            echo "   {$header}: {$value}\n";
        }
        
        // Configurar request
        $_SERVER['REQUEST_METHOD'] = $testCase['method'];
        $_SERVER['REQUEST_URI'] = $testCase['path'];
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        $request = Request::capture();
        
        // Procesar request
        $response = $app->handle($request);
        
        echo "   Status: {$response->getStatusCode()}\n";
        
        $content = json_decode($response->getContent(), true);
        if (isset($content['message'])) {
            echo "   Message: {$content['message']}\n";
        }
        if (isset($content['error'])) {
            echo "   Error: {$content['error']}\n";
        }
        
        // Mostrar headers de respuesta interesantes
        $headers = $response->getHeaders();
        $interestingHeaders = ['Access-Control-Allow-Origin', 'Access-Control-Allow-Methods'];
        foreach ($interestingHeaders as $headerName) {
            if (isset($headers[$headerName])) {
                echo "   {$headerName}: {$headers[$headerName]}\n";
            }
        }
        
        echo "\n";
        
        // Limpiar headers para el siguiente test
        foreach ($testCase['headers'] as $header => $value) {
            unset($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))]);
        }
    }
    
    echo "✅ Middleware tests completed!\n\n";
    
    echo "📋 Available Tokens for Testing:\n";
    echo "--------------------------------\n";
    echo "• test-token-123 (admin): Full access\n";
    echo "• user-token-456 (user): Limited access\n";
    echo "• demo-token-789 (demo): Demo access\n\n";
    
    echo "🔧 How to test manually:\n";
    echo "------------------------\n";
    echo "curl -H \"Authorization: Bearer test-token-123\" http://localhost/api/users/profile\n";
    echo "curl -H \"Authorization: Bearer user-token-456\" -X DELETE http://localhost/api/users/123\n";
    echo "curl -H \"Origin: https://example.com\" http://localhost/api/users/demo\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}