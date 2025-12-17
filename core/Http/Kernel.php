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
            // Ejecutar middleware global antes del routing
            if (!empty($this->middleware)) {
                $pipeline = new \Apollo\Core\Router\Pipeline($this->container, $this->middleware);
                $result = $pipeline->run($request);
                
                // Si el middleware devuelve una respuesta, usarla
                if ($result instanceof Response) {
                    return $result;
                }
                
                // Si el middleware modificó la request, usar la nueva
                if ($result instanceof Request) {
                    $request = $result;
                }
            }
            
            // Enrutar y ejecutar
            return $this->router->dispatch($request);
            
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
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