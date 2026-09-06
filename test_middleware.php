<?php
// test_middleware.php - Script para probar middlewares (sin DB)

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

    // Cargar configuración
    $config = $app->make('config');

    // Registrar Service Providers del core y de apps (igual que public/index.php)
    foreach ($config->get('providers.core', []) as $providerClass) {
        if (class_exists($providerClass)) {
            $app->registerServiceProvider(new $providerClass($app));
        }
    }

    foreach ($config->get('providers.app', []) as $providerClass) {
        if (class_exists($providerClass)) {
            $app->registerServiceProvider(new $providerClass($app));
        }
    }

    // Registrar apps desde configuración
    foreach ($config->get('apps.registered', []) as $appName) {
        $app->registerApp($appName);
    }

    echo "✅ Application initialized with middlewares\n\n";

    $failures = 0;

    $run = function (string $method, string $path, array $headers = []) use ($app) {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'HTTP_HOST' => 'localhost',
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = new Request([], [], [], [], [], $server, '');
        $response = $app->handle($request);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    };

    $check = function (string $name, int $expected, array $actual) use (&$failures) {
        $status = $actual[0];
        $ok = $status === $expected;
        if (!$ok) {
            $failures++;
        }
        echo ($ok ? "✅" : "❌") . " {$name}: esperado {$expected}, obtenido {$status}\n";
    };

    // Ruta con logging (sin auth)
    [$status, $body] = $run('GET', '/api/users/test');
    $check('Ruta pública con LoggingMiddleware (200)', 200, [$status, $body]);

    // Ruta protegida sin token -> 401
    [$status, $body] = $run('GET', '/api/users/profile');
    $check('Ruta protegida sin token (401)', 401, [$status, $body]);

    // Ruta protegida con token inválido -> 401
    [$status, $body] = $run('GET', '/api/users/profile', ['Authorization' => 'Bearer invalid-token']);
    $check('Ruta protegida con token inválido (401)', 401, [$status, $body]);

    // Ruta admin sin token -> 401 (se detiene en auth antes de roles)
    [$status, $body] = $run('GET', '/api/auth/admin/users');
    $check('Ruta admin sin token (401)', 401, [$status, $body]);

    // Ruta inexistente -> 404
    [$status, $body] = $run('GET', '/api/no-existe');
    $check('Ruta inexistente (404)', 404, [$status, $body]);

    echo "\n";

    if ($failures > 0) {
        echo "❌ {$failures} chequeo(s) fallaron\n";
        exit(1);
    }

    echo "✅ Todos los chequeos pasaron\n";
    exit(0);

} catch (\Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}