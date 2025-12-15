<?php

namespace ApolloPHP\Core;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use Closure;
use ArrayAccess;

class Container implements ContainerInterface, ArrayAccess
{
    protected array $bindings = [];
    protected array $instances = [];
    protected array $aliases = [];
    protected array $resolved = [];
    
    public function __construct()
    {
        $this->instance(Container::class, $this);
        $this->instance(ContainerInterface::class, $this);
    }
    
    public function bind(string $id, $concrete = null, bool $shared = false): void
    {
        if (is_null($concrete)) {
            $concrete = $id;
        }
        
        if (!$concrete instanceof Closure) {
            if (!is_string($concrete)) {
                throw new \InvalidArgumentException('Concrete must be a string or Closure');
            }
            $concrete = $this->getClosure($id, $concrete);
        }
        
        $this->bindings[$id] = compact('concrete', 'shared');
    }
    
    public function singleton(string $id, $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }
    
    public function instance(string $id, $instance): void
    {
        $this->instances[$id] = $instance;
    }
    
    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }
    
    public function get(string $id)
    {
        try {
            return $this->resolve($id);
        } catch (\Exception $e) {
            if ($this->has($id)) {
                throw $e;
            }
            throw new class("Service {$id} not found") extends \Exception implements NotFoundExceptionInterface {};
        }
    }
    
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || 
               isset($this->instances[$id]) || 
               isset($this->aliases[$id]) ||
               class_exists($id);
    }
    
    public function call($callback, array $parameters = [])
    {
        if ($callback instanceof Closure) {
            return $this->callClosure($callback, $parameters);
        }
        
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }
        
        if (is_array($callback)) {
            return $this->callClassMethod($callback, $parameters);
        }
        
        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return $this->callClosure($callback, $parameters);
        }
        
        throw new \InvalidArgumentException('Invalid callback provided');
    }
    
    public function make(string $abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters);
    }
    
    public function resolved(string $abstract): bool
    {
        return isset($this->resolved[$abstract]) || 
               isset($this->instances[$abstract]);
    }
    
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->resolved = [];
    }
    
    protected function resolve(string $abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($abstract);
        
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        
        $object = $this->build($abstract, $parameters);
        
        if (isset($this->bindings[$abstract]['shared']) && 
            $this->bindings[$abstract]['shared'] === true) {
            $this->instances[$abstract] = $object;
        }
        
        $this->resolved[$abstract] = true;
        
        return $object;
    }
    
    protected function build($concrete, array $parameters = [])
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }
        
        if (!is_string($concrete)) {
            return $concrete;
        }
        
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (\ReflectionException $e) {
            throw new \Exception("Class {$concrete} does not exist", 0, $e);
        }
        
        if (!$reflector->isInstantiable()) {
            throw new \Exception("Class {$concrete} is not instantiable");
        }
        
        $constructor = $reflector->getConstructor();
        
        if (is_null($constructor)) {
            return new $concrete;
        }
        
        $dependencies = $this->resolveDependencies(
            $constructor->getParameters(), 
            $parameters
        );
        
        return $reflector->newInstanceArgs($dependencies);
    }
    
    protected function resolveDependencies(array $parameters, array $primitives = []): array
    {
        $dependencies = [];
        
        foreach ($parameters as $parameter) {
            $dependency = $parameter->getType();
            
            if (array_key_exists($parameter->getName(), $primitives)) {
                $dependencies[] = $primitives[$parameter->getName()];
            } elseif ($dependency && !$dependency->isBuiltin()) {
                $dependencies[] = $this->get($dependency->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } elseif ($parameter->isOptional()) {
                $dependencies[] = null;
            } else {
                throw new \Exception("Cannot resolve parameter \${$parameter->getName()}");
            }
        }
        
        return $dependencies;
    }
    
    protected function getClosure(string $abstract, string $concrete): Closure
    {
        return function ($container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete, $parameters);
            }
            
            return $container->make($concrete, $parameters);
        };
    }
    
    protected function callClosure(Closure $callback, array $parameters = [])
    {
        $dependencies = $this->resolveMethodDependencies($callback, $parameters);
        return $callback(...$dependencies);
    }
    
    protected function callClassMethod(array $callback, array $parameters = [])
    {
        [$class, $method] = $callback;
        
        if (is_string($class)) {
            $class = $this->get($class);
        }
        
        $reflector = new ReflectionMethod($class, $method);
        
        $dependencies = $this->resolveMethodDependencies($reflector, $parameters);
        
        return $reflector->invokeArgs($class, $dependencies);
    }
    
    protected function resolveMethodDependencies($method, array $parameters = []): array
    {
        if ($method instanceof Closure) {
            $reflector = new ReflectionFunction($method);
        } else {
            $reflector = $method;
        }
        
        $dependencies = [];
        
        foreach ($reflector->getParameters() as $parameter) {
            $dependency = $parameter->getType();
            
            if (array_key_exists($parameter->getName(), $parameters)) {
                $dependencies[] = $parameters[$parameter->getName()];
                unset($parameters[$parameter->getName()]);
            } elseif (!is_null($dependency) && !$dependency->isBuiltin()) {
                $dependencies[] = $this->get($dependency->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } elseif (!empty($parameters)) {
                $dependencies[] = array_shift($parameters);
            } else {
                throw new \Exception("Cannot resolve parameter \${$parameter->getName()}");
            }
        }
        
        return array_merge($dependencies, $parameters);
    }
    
    protected function getAlias(string $id): string
    {
        return $this->aliases[$id] ?? $id;
    }
    
    public function __get(string $key)
    {
        return $this->get($key);
    }
    
    public function __set(string $key, $value)
    {
        $this->bind($key, $value);
    }
    
    public function __isset(string $key): bool
    {
        return $this->has($key);
    }
    
    public function __unset(string $key): void
    {
        unset($this->bindings[$key], $this->instances[$key], $this->aliases[$key]);
    }
    
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }
    
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }
    
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->bind($offset, $value);
    }
    
    public function offsetUnset(mixed $offset): void
    {
        unset($this->bindings[$offset], $this->instances[$offset], $this->aliases[$offset]);
    }
}