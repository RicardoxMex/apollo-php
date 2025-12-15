<?php

namespace ApolloPHP\Core;

abstract class ServiceProvider
{
    protected Container $container;
    
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    
    abstract public function register(): void;
    
    public function boot(): void
    {
        // Puede ser sobrescrito por providers hijos
    }
}