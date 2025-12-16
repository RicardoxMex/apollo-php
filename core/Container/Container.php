<?php
// core/Container/Container.php

namespace Apollo\Core\Container;

use Closure;
use ReflectionClass;
use ReflectionParameter;
use ReflectionFunction;
use ReflectionMethod;
use InvalidArgumentException;

class Container
{
    private static ?Container $instance = null;
    private array $bindings = [];
    private array $instances = [];
    private array $aliases = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        if (!$concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
    }

    private function getClosure(string $abstract, string $concrete): Closure
    {
        return function ($container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete, $parameters);
            }

            return $container->make($concrete, $parameters);
        };
    }

    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function make(string $abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($abstract);

        // Si ya existe una instancia singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Obtener el concrete
        $concrete = $this->getConcrete($abstract);

        // Si es un Closure o invocable
        if ($concrete instanceof Closure) {
            $object = $concrete($this, $parameters);
        } else {
            $object = $this->build($concrete, $parameters);
        }

        // Si es singleton, guardar la instancia
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            [$class, $method] = explode('@', $callback);
            $callback = [$this->make($class), $method];
        }

        if (is_array($callback) && is_string($callback[0])) {
            $callback[0] = $this->make($callback[0]);
        }

        return $this->callBoundMethod($callback, $parameters, $defaultMethod);
    }

    protected function callBoundMethod($callback, array $parameters, $defaultMethod)
    {
        if (is_string($callback)) {
            $callback = $this->getCallableFromString($callback, $defaultMethod);
        }

        if (is_array($callback)) {
            $dependencies = $this->getMethodDependencies($callback[0], $callback[1], $parameters);
            return $callback[0]->{$callback[1]}(...$dependencies);
        }

        if ($callback instanceof Closure) {
            $dependencies = $this->getFunctionDependencies($callback, $parameters);
            return $callback(...$dependencies);
        }

        throw new ContainerException('Invalid callback provided');
    }

    protected function getCallableFromString(string $callback, $defaultMethod)
    {
        if (!str_contains($callback, '@')) {
            if (!method_exists($callback, $defaultMethod)) {
                throw new ContainerException("Method {$defaultMethod} not found on {$callback}");
            }
            return [$this->make($callback), $defaultMethod];
        }

        [$class, $method] = explode('@', $callback);
        return [$this->make($class), $method];
    }

    protected function getMethodDependencies($object, string $method, array $parameters = [])
    {
        $reflector = new ReflectionMethod($object, $method);

        return $this->resolveDependencies(
            $reflector->getParameters(),
            $parameters
        );
    }

    protected function getFunctionDependencies(Closure $function, array $parameters = [])
    {
        $reflector = new ReflectionFunction($function);

        return $this->resolveDependencies(
            $reflector->getParameters(),
            $parameters
        );
    }

    private function resolveDependencies(array $dependencies, array $parameters = [])
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            $this->resolveDependency($dependency, $parameters, $results);
        }

        return $results;
    }

    private function resolveDependency(ReflectionParameter $dependency, array $parameters, array &$results)
    {
        $name = $dependency->getName();

        // Si el parámetro fue proporcionado
        if (array_key_exists($name, $parameters)) {
            $results[] = $parameters[$name];
            return;
        }

        // Si es tipo-clase, resolver del container
        if ($type = $dependency->getType()) {
            if (!$type->isBuiltin() && $this->has($type->getName())) {
                $results[] = $this->make($type->getName());
                return;
            }
        }

        // Valor por defecto
        if ($dependency->isDefaultValueAvailable()) {
            $results[] = $dependency->getDefaultValue();
            return;
        }

        // Si es variadic, retornar array vacío
        if ($dependency->isVariadic()) {
            $results[] = [];
            return;
        }

        throw new ContainerException("Unresolvable dependency: {$name}");
    }

    public function instance(string $abstract, $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    private function getConcrete(string $abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    private function isShared(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) &&
            $this->bindings[$abstract]['shared'] === true;
    }

    private function getAlias(string $abstract): string
    {
        return $this->aliases[$abstract] ?? $abstract;
    }

    private function build(string $concrete, array $parameters = [])
    {
        try {
            // VERIFICA SI LA CLASE EXISTE
            if (!class_exists($concrete) && !interface_exists($concrete)) {
                throw new ContainerException("Class or interface '{$concrete}' does not exist");
            }

            $reflector = new ReflectionClass($concrete);

            if (!$reflector->isInstantiable()) {
                throw new InvalidArgumentException("Class {$concrete} is not instantiable");
            }

            $constructor = $reflector->getConstructor();

            if (is_null($constructor)) {
                return new $concrete;
            }

            $dependencies = $this->resolveDependencies($constructor->getParameters(), $parameters);

            return $reflector->newInstanceArgs($dependencies);
        } catch (\Exception $e) {
            throw new ContainerException("Could not build {$concrete}: " . $e->getMessage());
        }
    }

    public function get($id)
    {
        return $this->make($id);
    }

    public function has($id): bool
    {
        return isset($this->bindings[$id]) ||
            isset($this->instances[$id]) ||
            isset($this->aliases[$id]) ||
            class_exists($id);
    }
}