<?php
namespace Apollo\Core\Container;

abstract class ServiceProvider {
    protected Container $container;
    
    public function __construct(Container $container) {
        $this->container = $container;
    }
    
    abstract public function register(): void;
    
    public function boot(): void {
        // Opcional: código a ejecutar después de registrar todos los providers
    }
    
    protected function mergeConfigFrom(string $path, string $key): void {
        $config = require $path;
        
        $existing = $this->container->has('config') 
            ? $this->container->get('config')
            : [];
        
        $this->container->instance('config', array_merge_recursive($existing, [$key => $config]));
    }
}