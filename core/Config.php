<?php
// core/Config.php

namespace Apollo\Core;

class Config {
    private array $items = [];
    
    public function __construct(array $items = []) {
        $this->items = $items;
    }
    
    public function get(string $key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->items;
        
        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        
        return $value;
    }
    
    public function set(string $key, $value): void {
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
    
    public function has(string $key): bool {
        $keys = explode('.', $key);
        $array = $this->items;
        
        foreach ($keys as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return false;
            }
            $array = $array[$segment];
        }
        
        return true;
    }
    
    public function all(): array {
        return $this->items;
    }
    
    public function merge(array $items): void {
        $this->items = array_merge_recursive($this->items, $items);
    }
    
    public function loadFromFile(string $path): void {
        if (file_exists($path)) {
            $config = require $path;
            if (is_array($config)) {
                $this->merge($config);
            }
        }
    }
}