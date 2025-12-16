<?php
// core/Http/Kernel.php

namespace Apollo\Core\Http;

use Apollo\Core\Container\Container;
use Apollo\Core\Router\Router;
use Throwable;

class Kernel {
    private Container $container;
    private Router $router;
    private array $middleware = [];
    
    public function __construct(Container $container, Router $router) {
        $this->container = $container;
        $this->router = $router;
    }
    
    public function handle(Request $request): Response {
        try {
            // Ejecutar middleware global
            $response = $this->sendThroughMiddleware($request);
            
            if ($response instanceof Response) {
                return $response;
            }
            
            // Enrutar y ejecutar
            return $this->router->dispatch($request);
            
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }
    
    private function sendThroughMiddleware(Request $request) {
        if (empty($this->middleware)) {
            return null;
        }
        
        $pipeline = new \Apollo\Core\Router\Pipeline($this->container, $this->middleware);
        
        return $pipeline->send($request)
            ->then(function($request) {
                // Continuar con el router
                return null;
            });
    }
    
    private function handleException(Throwable $e): Response {
        $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        
        $data = [
            'error' => 'Internal Server Error',
            'message' => $_ENV['APP_DEBUG'] ? $e->getMessage() : 'Something went wrong',
        ];
        
        if ($_ENV['APP_DEBUG']) {
            $data['trace'] = $e->getTraceAsString();
            $data['file'] = $e->getFile();
            $data['line'] = $e->getLine();
        }
        
        return Response::json($data, $status);
    }
    
    public function setMiddleware(array $middleware): void {
        $this->middleware = $middleware;
    }
    
    public function getMiddleware(): array {
        return $this->middleware;
    }
}