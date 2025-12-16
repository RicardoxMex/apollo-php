<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';

// Inicializar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Mostrar que funciona
dd(env('APP_DEBUG'));
// Probar autoloading
if (class_exists('Apollo\Core\Container\Container')) {
    echo "✅ Container cargado correctamente\n";
}

if (class_exists('Apollo\Core\Router\Router')) {
    echo "✅ Router cargado correctamente\n";
}