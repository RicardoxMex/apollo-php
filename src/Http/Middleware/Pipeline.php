<?php

namespace ApolloPHP\Http\Middleware;

use ApolloPHP\Core\Container;
use ApolloPHP\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Closure;

class Pipeline
{
    protected Container $container;
    protected Request $passable;
    protected array $pipes = [];
    protected string $method = 'handle';
    
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    
    public function send(Request $passable): self
    {
        $this->passable = $passable;
        return $this;
    }
    
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;
        return $this;
    }
    
    public function via(string $method): self
    {
        $this->method = $method;
        return $this;
    }
    
    public function then(Closure $destination)
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $this->prepareDestination($destination)
        );
        
        return $pipeline($this->passable);
    }
    
    protected function prepareDestination(Closure $destination): Closure
    {
        return function ($passable) use ($destination) {
            return $destination($passable);
        };
    }
    
    protected function carry(): Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                if (is_callable($pipe)) {
                    return $pipe($passable, $stack);
                }
                
                if (is_string($pipe)) {
                    $pipe = $this->container->make($pipe);
                }
                
                if (method_exists($pipe, $this->method)) {
                    return $pipe->{$this->method}($passable, $stack);
                }
                
                return $pipe($passable, $stack);
            };
        };
    }
}