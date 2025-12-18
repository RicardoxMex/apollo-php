<?php
// setup_database.php - Script para configurar la base de datos

require_once 'vendor/autoload.php';

use Apollo\Core\Database\Connection\DatabaseManager;

try {
    echo "Configurando base de datos...\n";
    
    // Inicializar la conexión a la base de datos
    $pdo = DatabaseManager::getConnection();
    
    // Ejecutar migración
    echo "Ejecutando migración...\n";
    $migration = file_get_contents('database/migrations/001_create_users_table.sql');
    $pdo->exec($migration);
    echo "✓ Tabla users creada\n";
    
    // Ejecutar seeder
    echo "Insertando datos de prueba...\n";
    $seeder = file_get_contents('database/seeds/users_seeder.sql');
    $pdo->exec($seeder);
    echo "✓ Datos de prueba insertados\n";
    
    echo "\n¡Base de datos configurada correctamente!\n";
    echo "Puedes probar la API en: http://localhost:8080/api/users?search=test\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}