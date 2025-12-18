<?php
// setup_database.php - Script para configurar la base de datos

require_once 'vendor/autoload.php';

// Cargar variables de entorno
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

use Apollo\Core\Database\Connection\DatabaseManager;

try {
    echo "Configurando base de datos...\n";
    
    // Configurar DatabaseManager con variables de entorno
    DatabaseManager::setConfig([
        'connection' => $_ENV['DB_CONNECTION'] ?? 'mysql',
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? 3306,
        'database' => $_ENV['DB_DATABASE'] ?? 'apollo',
        'username' => $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
        'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci'
    ]);
    
    // Inicializar la conexión a la base de datos
    $pdo = DatabaseManager::getConnection();
    
    echo "✓ Conexión a base de datos establecida\n";
    
    // Ejecutar migraciones
    echo "Ejecutando migraciones...\n";
    
    $migrationFiles = [
        'database/migrations/001_create_users_table.php',
        'database/migrations/002_create_roles_table.php',
        'database/migrations/003_create_user_roles_table.php',
        'database/migrations/004_create_user_sessions_table.php',
        'database/migrations/005_create_password_resets_table.php',
        'database/migrations/006_create_rate_limits_table.php'
    ];
    
    // Primero eliminar tablas existentes en orden inverso (por las foreign keys)
    echo "Eliminando tablas existentes...\n";
    $tablesToDrop = ['rate_limits', 'password_resets', 'user_sessions', 'user_roles', 'roles', 'users'];
    foreach ($tablesToDrop as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            echo "✓ Tabla {$table} eliminada\n";
        } catch (Exception $e) {
            echo "⚠️  No se pudo eliminar {$table}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\nCreando tablas...\n";
    foreach ($migrationFiles as $file) {
        if (file_exists($file)) {
            echo "Ejecutando migración: " . basename($file) . "\n";
            $migration = require $file;
            $migration->up();
            echo "✓ " . basename($file) . " ejecutada\n";
        } else {
            echo "⚠️  Archivo de migración no encontrado: $file\n";
        }
    }
    
    echo "\n✅ ¡Base de datos configurada correctamente!\n";
    echo "Ahora puedes ejecutar: php run_seeders.php\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}