<?php

namespace ApolloPHP\Support;

use ArrayAccess;

class Config implements ArrayAccess
{
    protected array $items = [];
    
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }
    
    public function load(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        
        foreach (glob($path . '/*.php') as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $this->items[$key] = require $file;
        }
    }
    
    public function get(string $key, $default = null)
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }
        
        if (strpos($key, '.') === false) {
            return $default;
        }
        
        $array = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }
        
        return $array;
    }
    
    public function set(string $key, $value): void
    {
        $keys = explode('.', $key);
        $array = &$this->items;
        
        while (count($keys) > 1) {
            $key = array_shift($keys);
            
            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }
            
            $array = &$array[$key];
        }
        
        $array[array_shift($keys)] = $value;
    }
    
    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->items)) {
            return true;
        }
        
        $array = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return false;
            }
            $array = $array[$segment];
        }
        
        return true;
    }
    
    public function push(string $key, $value): void
    {
        $array = $this->get($key, []);
        $array[] = $value;
        $this->set($key, $array);
    }
    
    public function all(): array
    {
        return $this->items;
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
        $this->set($offset, $value);
    }
    
    public function offsetUnset(mixed $offset): void
    {
        $this->set($offset, null);
    }
}