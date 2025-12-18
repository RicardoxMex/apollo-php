<?php

require_once __DIR__ . '/vendor/autoload.php';

// Cargar variables de entorno
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Crear aplicación
$app = new Apollo\Core\Application(__DIR__);

// Cargar configuración
$config = $app->make('config');

// Registrar Core Service Providers
$coreProviders = $config->get('providers.core', []);
foreach ($coreProviders as $providerClass) {
    if (class_exists($providerClass)) {
        $app->registerServiceProvider(new $providerClass($app));
    }
}

// Registrar apps
$registeredApps = $config->get('apps.registered', []);
foreach ($registeredApps as $appName) {
    try {
        $app->registerApp($appName);
    } catch (Exception $e) {
        echo "⚠️  App '{$appName}' error: " . $e->getMessage() . "\n";
    }
}

$app->bootServiceProviders();

echo "🚀 Running Apollo Seeders...\n\n";

// Ejecutar seeder de roles
require_once __DIR__ . '/database/seeds/RolesSeeder.php';
$rolesSeeder = new RolesSeeder();
$rolesSeeder->run();

echo "\n✅ All seeders completed successfully!\n";