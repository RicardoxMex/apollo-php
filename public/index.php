<?php
// public/index.php

// Iniciar buffer para evitar problemas de headers
ob_start();

require_once __DIR__ . '/../vendor/autoload.php';

// Inicializar variables de entorno
if (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

// Crear aplicación
$app = new Apollo\Core\Application(dirname(__DIR__));

// Cargar providers del core
$app->registerServiceProvider(new Apollo\Core\Providers\AppServiceProvider($app));

// Configuración se carga automáticamente
$config = $app->make('config');

// Registrar apps desde configuración
$apps = $config->get('apps', []);
foreach ($apps as $appName) {
    try {
        $app->registerApp($appName);
        error_log("✅ App '{$appName}' registered");
    } catch (Exception $e) {
        error_log("⚠️  App '{$appName}' error: " . $e->getMessage());
    }
}

// Boot service providers
$app->bootServiceProviders();

// Manejar la request
$response = $app->handle(
    Apollo\Core\Http\Request::capture()
);

// Enviar respuesta
ob_clean(); // Limpiar cualquier output previo
$response->send();