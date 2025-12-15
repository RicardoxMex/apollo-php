<?php

namespace ApolloPHP\Support;

class Arr
{
    public static function accessible($value): bool
    {
        return is_array($value) || $value instanceof \ArrayAccess;
    }
    
    public static function exists($array, $key): bool
    {
        if ($array instanceof \ArrayAccess) {
            return $array->offsetExists($key);
        }
        
        return array_key_exists($key, $array);
    }
    
    public static function get($array, $key, $default = null)
    {
        if (!static::accessible($array)) {
            return value($default);
        }
        
        if (is_null($key)) {
            return $array;
        }
        
        if (static::exists($array, $key)) {
            return $array[$key];
        }
        
        if (!str_contains($key, '.')) {
            return $array[$key] ?? value($default);
        }
        
        foreach (explode('.', $key) as $segment) {
            if (static::accessible($array) && static::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return value($default);
            }
        }
        
        return $array;
    }
    
    public static function set(&$array, $key, $value): array
    {
        if (is_null($key)) {
            return $array = $value;
        }
        
        $keys = explode('.', $key);
        
        foreach ($keys as $i => $key) {
            if (count($keys) === 1) {
                break;
            }
            
            unset($keys[$i]);
            
            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }
            
            $array = &$array[$key];
        }
        
        $array[array_shift($keys)] = $value;
        
        return $array;
    }
    
    public static function has($array, $keys): bool
    {
        $keys = (array) $keys;
        
        if (!$array || $keys === []) {
            return false;
        }
        
        foreach ($keys as $key) {
            $subKeyArray = $array;
            
            if (static::exists($array, $key)) {
                continue;
            }
            
            foreach (explode('.', $key) as $segment) {
                if (static::accessible($subKeyArray) && static::exists($subKeyArray, $segment)) {
                    $subKeyArray = $subKeyArray[$segment];
                } else {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    public static function forget(&$array, $keys): void
    {
        $original = &$array;
        
        $keys = (array) $keys;
        
        if (count($keys) === 0) {
            return;
        }
        
        foreach ($keys as $key) {
            if (static::exists($array, $key)) {
                unset($array[$key]);
                continue;
            }
            
            $parts = explode('.', $key);
            $array = &$original;
            
            while (count($parts) > 1) {
                $part = array_shift($parts);
                
                if (isset($array[$part]) && is_array($array[$part])) {
                    $array = &$array[$part];
                } else {
                    continue 2;
                }
            }
            
            unset($array[array_shift($parts)]);
        }
    }
    
    public static function only($array, $keys): array
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }
    
    public static function except($array, $keys): array
    {
        static::forget($array, $keys);
        
        return $array;
    }
    
    public static function pluck($array, $value, $key = null): array
    {
        $results = [];
        
        [$value, $key] = static::explodePluckParameters($value, $key);
        
        foreach ($array as $item) {
            $itemValue = data_get($item, $value);
            
            if (is_null($key)) {
                $results[] = $itemValue;
            } else {
                $itemKey = data_get($item, $key);
                
                if (is_object($itemKey) && method_exists($itemKey, '__toString')) {
                    $itemKey = (string) $itemKey;
                }
                
                $results[$itemKey] = $itemValue;
            }
        }
        
        return $results;
    }
    
    public static function pull(&$array, $key, $default = null)
    {
        $value = static::get($array, $key, $default);
        
        static::forget($array, $key);
        
        return $value;
    }
    
    public static function random($array, $number = null)
    {
        $requested = is_null($number) ? 1 : $number;
        
        $count = count($array);
        
        if ($requested > $count) {
            throw new \InvalidArgumentException(
                "You requested {$requested} items, but there are only {$count} items available."
            );
        }
        
        if (is_null($number)) {
            return $array[array_rand($array)];
        }
        
        if ((int) $number === 0) {
            return [];
        }
        
        $keys = array_rand($array, $number);
        
        $results = [];
        
        foreach ((array) $keys as $key) {
            $results[] = $array[$key];
        }
        
        return $results;
    }
    
    public static function flatten($array, $depth = INF): array
    {
        $result = [];
        
        foreach ($array as $item) {
            $item = $item instanceof Collection ? $item->all() : $item;
            
            if (!is_array($item)) {
                $result[] = $item;
            } else {
                $values = $depth === 1
                    ? array_values($item)
                    : static::flatten($item, $depth - 1);
                
                foreach ($values as $value) {
                    $result[] = $value;
                }
            }
        }
        
        return $result;
    }
    
    public static function wrap($value): array
    {
        if (is_null($value)) {
            return [];
        }
        
        return is_array($value) ? $value : [$value];
    }
    
    protected static function explodePluckParameters($value, $key): array
    {
        $value = is_string($value) ? explode('.', $value) : $value;
        
        $key = is_null($key) || is_array($key) ? $key : explode('.', $key);
        
        return [$value, $key];
    }
}