<?php

namespace ApolloAuth\Providers;

use ApolloPHP\Core\ServiceProvider;
use ApolloAuth\Services\AuthService;
use ApolloAuth\Services\JwtService;
use ApolloAuth\Services\PasswordService;
use ApolloAuth\Middleware\Authenticate;
use ApolloAuth\Middleware\Authorize;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerServices();
        $this->registerMiddleware();
    }
    
    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerConfig();
    }
    
    protected function registerServices(): void
    {


    }
    
    protected function registerMiddleware(): void
    {
    }
    
    protected function registerPolicies(): void
    {
        $config = $this->container->get('config');
        $policies = $config->get('auth.policies', []);
        
        foreach ($policies as $model => $policy) {
            // Aquí podrías registrar políticas si implementas un Gate system
        }
    }
    
    protected function registerConfig(): void
    {
        $config = $this->container->get('config');
        $moduleConfig = require __DIR__ . '/../config.php';
        
        $config->set('auth', array_merge(
            $config->get('auth', []),
            $moduleConfig
        ));
    }
}