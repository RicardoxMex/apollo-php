<?php
// core/Router/Pipeline.php

namespace Apollo\Core\Router;

use Apollo\Core\Container\Container;
use Closure;

class Pipeline {
    private Container $container;
    private array $middleware;
    private mixed $passable;
    
    public function __construct(Container $container, array $middleware) {
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
            function($passable) use ($destination) {
                return $destination($passable);
            }
        );
        
        return $pipeline($this->passable);
    }
    
    private function carry(): Closure {
        return function($stack, $middleware) {
            return function($passable) use ($stack, $middleware) {
                if (is_string($middleware)) {
                    $middleware = $this->container->make($middleware);
                }
                
                if (method_exists($middleware, 'handle')) {
                    return $middleware->handle($passable, $stack);
                }
                
                return $middleware($passable, $stack);
            };
        };
    }
}