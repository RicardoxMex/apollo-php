# Tutorial: Creando Comandos CLI Personalizados en Apollo Framework

Este tutorial te enseñará cómo crear comandos CLI personalizados para el framework Apollo, similar a como Laravel tiene `php artisan` o Symfony tiene `bin/console`.

## Índice

1. [Introducción](#introducción)
2. [Estructura de un Comando](#estructura-de-un-comando)
3. [Creando tu Primer Comando](#creando-tu-primer-comando)
4. [Registrando el Comando](#registrando-el-comando)
5. [Comandos Avanzados](#comandos-avanzados)
6. [Ejemplos Prácticos](#ejemplos-prácticos)
7. [Mejores Prácticas](#mejores-prácticas)

## Introducción

Apollo Framework incluye un sistema CLI robusto que te permite crear comandos personalizados para automatizar tareas comunes como:

- Generar código (controladores, middlewares, modelos)
- Ejecutar migraciones de base de datos
- Limpiar cachés
- Ejecutar tareas de mantenimiento
- Importar/exportar datos

## Estructura de un Comando

Todos los comandos en Apollo extienden la clase base `Apollo\Core\Console\Command` y deben implementar el método `handle()`.

```php
<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MiComandoPersonalizado extends Command
{
    protected string $signature = 'mi:comando';
    protected string $description = 'Descripción de mi comando';

    public function handle(): int
    {
        // Lógica del comando aquí
        return 0; // 0 = éxito, 1 = error
    }
}
```

### Propiedades Importantes

- **`$signature`**: El nombre del comando que se usará en la CLI
- **`$description`**: Descripción que aparece en la ayuda

### Métodos Disponibles

- **`handle()`**: Método principal que ejecuta la lógica del comando
- **`info($message)`**: Muestra mensaje en verde
- **`error($message)`**: Muestra mensaje en rojo
- **`warn($message)`**: Muestra mensaje en amarillo
- **`line($message)`**: Muestra mensaje normal
- **`table($headers, $rows)`**: Muestra una tabla formateada

## Creando tu Primer Comando

Vamos a crear un comando que genere un reporte del sistema.

### Paso 1: Crear el Archivo del Comando

Crea el archivo `core/Console/Commands/SystemReportCommand.php`:

```php
<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;

class SystemReportCommand extends Command
{
    protected string $signature = 'system:report';
    protected string $description = 'Generate a system report';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        $this->info('Apollo Framework System Report');
        $this->line('================================');
        $this->line();

        // Información básica
        $this->line('Framework Version: ' . $this->app->version());
        $this->line('PHP Version: ' . PHP_VERSION);
        $this->line('Environment: ' . $this->app->make('config')->get('env', 'unknown'));
        $this->line();

        // Apps cargadas
        $loadedApps = $this->app->getLoadedApps();
        $this->info('Loaded Apps (' . count($loadedApps) . '):');
        foreach ($loadedApps as $app) {
            $this->line('  - ' . $app);
        }
        $this->line();

        // Rutas registradas
        $router = $this->app->make('router');
        $routes = $router->getRoutes();
        $this->info('Total Routes: ' . count($routes));
        $this->line();

        $this->info('Report generated successfully!');
        
        return 0;
    }
}
```

### Paso 2: Registrar el Comando

Edita `core/Console/Kernel.php` y agrega tu comando:

```php
private function registerCommands(): void
{
    $this->commands = [
        'route:list' => Commands\RouteListCommand::class,
        'make:controller' => Commands\MakeControllerCommand::class,
        'make:middleware' => Commands\MakeMiddlewareCommand::class,
        'system:report' => Commands\SystemReportCommand::class, // ← Nuevo comando
        'help' => Commands\HelpCommand::class,
    ];
}
```

### Paso 3: Probar el Comando

```bash
php apollo system:report
```

## Comandos Avanzados

### Manejo de Argumentos

Para comandos que necesitan argumentos, puedes acceder a `$argv` global:

```php
public function handle(): int
{
    global $argv;
    
    if (count($argv) < 3) {
        $this->error('Nombre requerido.');
        $this->line('Uso: php apollo mi:comando <nombre>');
        return 1;
    }

    $nombre = $argv[2];
    $this->info("Hola, {$nombre}!");
    
    return 0;
}
```

### Manejo de Opciones

Para opciones como `--force` o `--app=users`:

```php
private function hasOption(array $argv, string $option): bool
{
    return in_array("--{$option}", $argv);
}

private function getOptionValue(array $argv, string $option): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$option}=")) {
            return substr($arg, strlen("--{$option}="));
        }
    }
    return null;
}

public function handle(): int
{
    global $argv;
    
    $force = $this->hasOption($argv, 'force');
    $appName = $this->getOptionValue($argv, 'app') ?: 'users';
    
    if ($force) {
        $this->warn('Modo forzado activado');
    }
    
    $this->info("Trabajando con app: {$appName}");
    
    return 0;
}
```

### Validación de Entrada

```php
private function validateInput(string $input, string $pattern, string $errorMessage): bool
{
    if (!preg_match($pattern, $input)) {
        $this->error($errorMessage);
        return false;
    }
    return true;
}

public function handle(): int
{
    global $argv;
    
    $className = $argv[2] ?? '';
    
    if (!$this->validateInput($className, '/^[A-Z][a-zA-Z0-9]*$/', 'Nombre de clase inválido')) {
        return 1;
    }
    
    // Continuar con la lógica...
    return 0;
}
```

## Ejemplos Prácticos

### 1. Comando para Limpiar Logs

```php
<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class ClearLogsCommand extends Command
{
    protected string $signature = 'logs:clear';
    protected string $description = 'Clear application logs';

    public function handle(): int
    {
        $logPath = 'storage/logs';
        
        if (!is_dir($logPath)) {
            $this->warn('Log directory not found');
            return 0;
        }

        $files = glob($logPath . '/*.log');
        $count = 0;

        foreach ($files as $file) {
            if (unlink($file)) {
                $count++;
            }
        }

        $this->info("Cleared {$count} log files");
        return 0;
    }
}
```

### 2. Comando para Generar Modelos

```php
<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MakeModelCommand extends Command
{
    protected string $signature = 'make:model';
    protected string $description = 'Create a new model class';

    public function handle(): int
    {
        global $argv;
        
        if (count($argv) < 3) {
            $this->error('Model name is required.');
            $this->line('Usage: php apollo make:model <ModelName> [--app=<app>]');
            return 1;
        }

        $modelName = $argv[2];
        $appName = $this->getAppOption($argv) ?: 'users';

        if (!$this->validateModelName($modelName)) {
            $this->error('Invalid model name. Use PascalCase (e.g., User, Product)');
            return 1;
        }

        $this->createModel($modelName, $appName);
        
        return 0;
    }

    private function getAppOption(array $argv): ?string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--app=')) {
                return substr($arg, 6);
            }
        }
        return null;
    }

    private function validateModelName(string $name): bool
    {
        return preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name);
    }

    private function createModel(string $modelName, string $appName): void
    {
        $appPath = "apps/{$appName}";
        $modelsPath = "{$appPath}/Models";
        
        if (!is_dir($modelsPath)) {
            mkdir($modelsPath, 0755, true);
        }

        $filePath = "{$modelsPath}/{$modelName}.php";
        
        if (file_exists($filePath)) {
            $this->error("Model {$modelName} already exists!");
            return;
        }

        $template = $this->getModelTemplate($modelName, $appName);
        
        file_put_contents($filePath, $template);
        
        $this->info("Model created successfully!");
        $this->line("Location: {$filePath}");
    }

    private function getModelTemplate(string $modelName, string $appName): string
    {
        $namespace = "Apps\\" . ucfirst($appName) . "\\Models";
        
        return "<?php

namespace {$namespace};

class {$modelName}
{
    protected array \$fillable = [];
    protected array \$hidden = [];
    
    public function __construct(array \$attributes = [])
    {
        \$this->fill(\$attributes);
    }
    
    public function fill(array \$attributes): self
    {
        foreach (\$attributes as \$key => \$value) {
            if (in_array(\$key, \$this->fillable)) {
                \$this->{\$key} = \$value;
            }
        }
        
        return \$this;
    }
    
    public function toArray(): array
    {
        \$attributes = get_object_vars(\$this);
        
        // Remover atributos ocultos
        foreach (\$this->hidden as \$hidden) {
            unset(\$attributes[\$hidden]);
        }
        
        return \$attributes;
    }
}
";
    }
}
```

### 3. Comando para Estadísticas de la Aplicación

```php
<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;

class StatsCommand extends Command
{
    protected string $signature = 'app:stats';
    protected string $description = 'Show application statistics';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        $this->info('Apollo Framework Statistics');
        $this->line('============================');
        $this->line();

        // Estadísticas de archivos
        $this->showFileStats();
        $this->line();

        // Estadísticas de rutas
        $this->showRouteStats();
        $this->line();

        // Estadísticas de apps
        $this->showAppStats();

        return 0;
    }

    private function showFileStats(): void
    {
        $phpFiles = $this->countFiles('**/*.php');
        $jsFiles = $this->countFiles('**/*.js');
        $cssFiles = $this->countFiles('**/*.css');

        $this->info('File Statistics:');
        $this->table(
            ['Type', 'Count'],
            [
                ['PHP Files', $phpFiles],
                ['JS Files', $jsFiles],
                ['CSS Files', $cssFiles],
                ['Total', $phpFiles + $jsFiles + $cssFiles]
            ]
        );
    }

    private function showRouteStats(): void
    {
        $router = $this->app->make('router');
        $routes = $router->getRoutes();

        $methodCounts = [];
        foreach ($routes as $route) {
            $method = $route->method;
            $methodCounts[$method] = ($methodCounts[$method] ?? 0) + 1;
        }

        $this->info('Route Statistics:');
        $rows = [];
        foreach ($methodCounts as $method => $count) {
            $rows[] = [$method, $count];
        }
        $rows[] = ['Total', count($routes)];

        $this->table(['Method', 'Count'], $rows);
    }

    private function showAppStats(): void
    {
        $loadedApps = $this->app->getLoadedApps();
        
        $this->info('App Statistics:');
        $this->line('Loaded Apps: ' . count($loadedApps));
        
        foreach ($loadedApps as $appName) {
            $controllersCount = $this->countFiles("apps/{$appName}/Controllers/*.php");
            $middlewareCount = $this->countFiles("apps/{$appName}/Middleware/*.php");
            
            $this->line("  {$appName}:");
            $this->line("    Controllers: {$controllersCount}");
            $this->line("    Middleware: {$middlewareCount}");
        }
    }

    private function countFiles(string $pattern): int
    {
        return count(glob($pattern));
    }
}
```

## Mejores Prácticas

### 1. Nomenclatura de Comandos

- Usa el formato `grupo:accion` (ej: `make:controller`, `cache:clear`)
- Mantén nombres descriptivos pero concisos
- Usa verbos en inglés para consistencia

### 2. Manejo de Errores

```php
public function handle(): int
{
    try {
        // Lógica del comando
        $this->info('Comando ejecutado exitosamente');
        return 0;
    } catch (\Exception $e) {
        $this->error('Error: ' . $e->getMessage());
        return 1;
    }
}
```

### 3. Validación Robusta

```php
private function validateRequiredArguments(array $argv, int $required): bool
{
    if (count($argv) < $required + 1) {
        $this->error('Argumentos insuficientes');
        return false;
    }
    return true;
}
```

### 4. Mensajes Informativos

```php
public function handle(): int
{
    $this->info('Iniciando proceso...');
    
    // Mostrar progreso
    for ($i = 1; $i <= 5; $i++) {
        $this->line("Procesando paso {$i}/5...");
        sleep(1);
    }
    
    $this->info('¡Proceso completado!');
    return 0;
}
```

### 5. Confirmaciones para Acciones Destructivas

```php
private function confirm(string $message): bool
{
    echo $message . ' (y/N): ';
    $handle = fopen('php://stdin', 'r');
    $response = trim(fgets($handle));
    fclose($handle);
    
    return strtolower($response) === 'y';
}

public function handle(): int
{
    if (!$this->confirm('¿Estás seguro de que quieres eliminar todos los logs?')) {
        $this->warn('Operación cancelada');
        return 0;
    }
    
    // Continuar con la eliminación...
    return 0;
}
```

## Registro Automático de Comandos

Para proyectos grandes, puedes crear un sistema de auto-registro:

```php
// En core/Console/Kernel.php
private function registerCommands(): void
{
    $this->commands = [];
    
    // Comandos del core
    $this->registerCoreCommands();
    
    // Comandos de apps
    $this->registerAppCommands();
}

private function registerCoreCommands(): void
{
    $commandsPath = __DIR__ . '/Commands';
    $files = glob($commandsPath . '/*Command.php');
    
    foreach ($files as $file) {
        $className = basename($file, '.php');
        $fullClassName = "Apollo\\Core\\Console\\Commands\\{$className}";
        
        if (class_exists($fullClassName)) {
            $command = $this->app->make($fullClassName);
            $this->commands[$command->getSignature()] = $fullClassName;
        }
    }
}
```

## Conclusión

El sistema CLI de Apollo Framework es potente y flexible. Con estos conocimientos puedes crear comandos personalizados que automaticen cualquier tarea en tu aplicación.

Recuerda:
- Mantén los comandos simples y enfocados
- Proporciona mensajes claros y útiles
- Valida siempre la entrada del usuario
- Maneja errores graciosamente
- Documenta tus comandos personalizados

¡Ahora tienes todas las herramientas para crear comandos CLI profesionales en Apollo Framework!