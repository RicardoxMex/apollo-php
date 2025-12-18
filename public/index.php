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

// Configuración se carga automáticamente
$config = $app->make('config');

// Registrar Core Service Providers
$coreProviders = $config->get('providers.core', []);
foreach ($coreProviders as $providerClass) {
    if (class_exists($providerClass)) {
        $app->registerServiceProvider(new $providerClass($app));
    }
}

// Registrar App Service Providers
$appProviders = $config->get('providers.app', []);
foreach ($appProviders as $providerClass) {
    if (class_exists($providerClass)) {
        $app->registerServiceProvider(new $providerClass($app));
    }
}

// Registrar apps desde configuración
$registeredApps = $config->get('apps.registered', []);
foreach ($registeredApps as $appName) {
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