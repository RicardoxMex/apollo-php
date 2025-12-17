<?php
// core/Router/Pipeline.php

namespace Apollo\Core\Router;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Closure;
use Exception;

class Pipeline {
    private Container $container;
    private array $middleware;
    private mixed $passable;
    
    public function __construct(Container $container, array $middleware = []) {
        $this->container = $container;
        $this->middleware = $middleware;
    }
    
    public function send($passable): self {
        $this->passable = $passable;
        return $this;
    }
    
    public function through(array $middleware): self {
        $this->middleware = $middleware;
        return $this;
    }
    
    public function then(Closure $destination) {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            $this->carry(),
            $destination
        );
        
        return $pipeline($this->passable);
    }
    
    private function carry(): Closure {
        return function($stack, $middleware) {
            return function($passable) use ($stack, $middleware) {
                try {
                    // Resolver middleware si es string
                    if (\is_string($middleware)) {
                        $middleware = $this->container->make($middleware);
                    }
                    
                    // Si es una clase con método handle
                    if (\is_object($middleware) && method_exists($middleware, 'handle')) {
                        return $middleware->handle($passable, $stack);
                    }
                    
                    // Si es un callable
                    if (\is_callable($middleware)) {
                        return $middleware($passable, $stack);
                    }
                    
                    throw new Exception("Invalid middleware: " . gettype($middleware));
                    
                } catch (Exception $e) {
                    // En caso de error en middleware, devolver respuesta de error
                    if ($passable instanceof Request) {
                        return Response::json([
                            'error' => 'Middleware Error',
                            'message' => $e->getMessage()
                        ], 500);
                    }
                    
                    throw $e;
                }
            };
        };
    }
    
    /**
     * Ejecutar middleware sin pipeline (para casos simples)
     */
    public function run($passable = null) {
        $passable = $passable ?? $this->passable;
        
        foreach ($this->middleware as $middleware) {
            try {
                if (\is_string($middleware)) {
                    $middleware = $this->container->make($middleware);
                }
                
                if (\is_object($middleware) && method_exists($middleware, 'handle')) {
                    $result = $middleware->handle($passable, function($p) { return $p; });
                } elseif (\is_callable($middleware)) {
                    $result = $middleware($passable, function($p) { return $p; });
                } else {
                    throw new Exception("Invalid middleware: " . gettype($middleware));
                }
                
                // Si el middleware devuelve una respuesta, detener la cadena
                if ($result instanceof Response) {
                    return $result;
                }
                
                // Actualizar el passable para el siguiente middleware
                if ($result !== null) {
                    $passable = $result;
                }
                
            } catch (Exception $e) {
                if ($passable instanceof Request) {
                    return Response::json([
                        'error' => 'Middleware Error',
                        'message' => $e->getMessage()
                    ], 500);
                }
                throw $e;
            }
        }
        
        return $passable;
    }
}