<?php

namespace ApolloPHP\Core;

use ReflectionClass;

class ModuleRegistry
{
    protected Application $app;
    protected array $modules = [];
    protected array $booted = [];
    protected array $paths = [];
    
    public function __construct(Application $app)
    {
        $this->app = $app;
        
        // Registrar path por defecto para módulos
        $this->paths[] = $app->modulesPath();
    }
    
    public function register(string $name, ?string $namespace = null): Module
    {
        if (isset($this->modules[$name])) {
            return $this->modules[$name];
        }
        
        $path = $this->resolvePath($name, $namespace);
        
        if (!$path || !is_dir($path)) {
            throw new \RuntimeException("Module [{$name}] not found.");
        }
        
        $module = new Module($name, $path, $this->app);
        $this->modules[$name] = $module;
        
        // Autoload del módulo
        $this->registerAutoload($module);
        
        return $module;
    }
    
    public function boot(): void
    {
        foreach ($this->modules as $name => $module) {
            if (!in_array($name, $this->booted, true)) {
                $module->boot();
                $this->booted[] = $name;
            }
        }
    }
    
    public function loadRoutes(): void
    {
        foreach ($this->modules as $module) {
            // Las rutas se cargan automáticamente en Module::boot()
        }
    }
    
    public function all(): array
    {
        return $this->modules;
    }
    
    public function get(string $name): ?Module
    {
        return $this->modules[$name] ?? null;
    }
    
    public function exists(string $name): bool
    {
        return isset($this->modules[$name]);
    }
    
    public function addPath(string $path): self
    {
        $this->paths[] = rtrim($path, '/\\');
        return $this;
    }
    
    protected function resolvePath(string $name, ?string $namespace = null): ?string
    {
        // Buscar en paths registrados
        foreach ($this->paths as $basePath) {
            $path = $basePath . '/' . $name;
            
            if (is_dir($path)) {
                return realpath($path);
            }
        }
        
        // Buscar por namespace (para módulos instalados via composer)
        if ($namespace) {
            try {
                $reflector = new ReflectionClass($namespace . '\\Module');
                return dirname($reflector->getFileName());
            } catch (\ReflectionException $e) {
                // Intentar encontrar por PSR-4
                return $this->findPathByNamespace($namespace);
            }
        }
        
        return null;
    }
    
    protected function findPathByNamespace(string $namespace): ?string
    {
        $loader = $this->getComposerLoader();
        
        if ($loader) {
            $prefixes = $loader->getPrefixesPsr4();
            
            foreach ($prefixes as $prefix => $paths) {
                if (str_starts_with($namespace, $prefix)) {
                    $relativePath = str_replace($prefix, '', $namespace);
                    $relativePath = str_replace('\\', '/', $relativePath);
                    
                    foreach ($paths as $path) {
                        $fullPath = rtrim($path, '/') . '/' . $relativePath;
                        
                        if (is_dir($fullPath)) {
                            return realpath($fullPath);
                        }
                    }
                }
            }
        }
        
        return null;
    }
    
    protected function registerAutoload(Module $module): void
    {
        $composerFile = $module->getPath() . '/composer.json';
        
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);
            
            if (isset($composer['autoload']['psr-4'])) {
                $loader = $this->getComposerLoader();
                
                if ($loader) {
                    foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
                        $loader->addPsr4(
                            $namespace,
                            $module->getPath() . '/' . $path
                        );
                    }
                }
            }
        }
    }
    
    protected function getComposerLoader()
    {
        return class_exists(\Composer\Autoload\ClassLoader::class) 
            ? include $this->app->basePath('vendor/autoload.php')
            : null;
    }
}