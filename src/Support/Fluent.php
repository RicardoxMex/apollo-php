<?php

namespace ApolloPHP\Support;

use ArrayAccess;
use JsonSerializable;

class Fluent implements ArrayAccess, JsonSerializable
{
    protected array $attributes = [];
    
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }
    
    public function get(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }
    
    public function set(string $key, $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }
    
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }
    
    public function forget(string $key): self
    {
        unset($this->attributes[$key]);
        return $this;
    }
    
    public function all(): array
    {
        return $this->attributes;
    }
    
    public function merge(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }
    
    public function toArray(): array
    {
        return $this->attributes;
    }
    
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }
    
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
    
    public function __get(string $key)
    {
        return $this->get($key);
    }
    
    public function __set(string $key, $value): void
    {
        $this->set($key, $value);
    }
    
    public function __isset(string $key): bool
    {
        return $this->has($key);
    }
    
    public function __unset(string $key): void
    {
        $this->forget($key);
    }
    
    public function __toString(): string
    {
        return $this->toJson();
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
        $this->forget($offset);
    }
}