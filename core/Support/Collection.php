<?php

namespace Apollo\Core\Support;

class Collection
{
    protected array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Get all items
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get item count
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if collection is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Get first item
     */
    public function first()
    {
        return reset($this->items) ?: null;
    }

    /**
     * Get last item
     */
    public function last()
    {
        return end($this->items) ?: null;
    }

    /**
     * Map over items
     */
    public function map(callable $callback): self
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * Filter items
     */
    public function filter(callable $callback = null): self
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback));
        }
        return new static(array_filter($this->items));
    }

    /**
     * Pluck values by key
     */
    public function pluck(string $key): array
    {
        $result = [];
        foreach ($this->items as $item) {
            if (is_array($item) && isset($item[$key])) {
                $result[] = $item[$key];
            } elseif (is_object($item) && isset($item->$key)) {
                $result[] = $item->$key;
            } elseif (is_object($item) && method_exists($item, 'getAttribute')) {
                $result[] = $item->getAttribute($key);
            }
        }
        return $result;
    }

    /**
     * Check if any item matches condition
     */
    public function some(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get items as array
     */
    public function toArray(): array
    {
        return array_map(function ($item) {
            return is_object($item) && method_exists($item, 'toArray') 
                ? $item->toArray() 
                : $item;
        }, $this->items);
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Array access
     */
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet($offset)
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Iterator
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}