<?php

namespace ApolloPHP\Support;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    protected array $items = [];
    
    public function __construct($items = [])
    {
        $this->items = $this->getArrayableItems($items);
    }
    
    public static function make($items = []): self
    {
        return new static($items);
    }
    
    public function all(): array
    {
        return $this->items;
    }
    
    public function avg($callback = null)
    {
        if ($count = $this->count()) {
            return $this->sum($callback) / $count;
        }
        
        return null;
    }
    
    public function average($callback = null)
    {
        return $this->avg($callback);
    }
    
    public function chunk(int $size): static
    {
        $chunks = [];
        
        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }
        
        return new static($chunks);
    }
    
    public function collapse(): static
    {
        $results = [];
        
        foreach ($this->items as $values) {
            if ($values instanceof self) {
                $values = $values->all();
            } elseif (!is_array($values)) {
                continue;
            }
            
            $results[] = $values;
        }
        
        return new static(array_merge([], ...$results));
    }
    
    public function combine($values): static
    {
        return new static(array_combine($this->all(), $this->getArrayableItems($values)));
    }
    
    public function concat($source): static
    {
        $result = new static($this->items);
        
        foreach ($source as $item) {
            $result->push($item);
        }
        
        return $result;
    }
    
    public function contains($key, $operator = null, $value = null): bool
    {
        if (func_num_args() === 1) {
            if ($this->useAsCallable($key)) {
                $placeholder = new \stdClass;
                
                return $this->first($key, $placeholder) !== $placeholder;
            }
            
            return in_array($key, $this->items);
        }
        
        return $this->contains($this->operatorForWhere(...func_get_args()));
    }
    
    public function count(): int
    {
        return count($this->items);
    }
    
    public function countBy($callback = null): static
    {
        if (is_null($callback)) {
            $callback = function ($value) {
                return $value;
            };
        }
        
        return new static(array_count_values(array_map($callback, $this->items)));
    }
    
    public function diff($items): static
    {
        return new static(array_diff($this->items, $this->getArrayableItems($items)));
    }
    
    public function diffAssoc($items): static
    {
        return new static(array_diff_assoc($this->items, $this->getArrayableItems($items)));
    }
    
    public function diffKeys($items): static
    {
        return new static(array_diff_key($this->items, $this->getArrayableItems($items)));
    }
    
    public function duplicates($callback = null, $strict = false): static
    {
        $items = $this->map($callback)->all();
        
        $duplicates = [];
        
        foreach (array_count_values($items) as $value => $count) {
            if ($count > 1) {
                $duplicates[] = $value;
            }
        }
        
        return new static($duplicates);
    }
    
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }
        
        return $this;
    }
    
    public function every($key, $operator = null, $value = null): bool
    {
        if (func_num_args() === 1) {
            $callback = $this->valueRetriever($key);
            
            foreach ($this->items as $k => $v) {
                if (!$callback($v, $k)) {
                    return false;
                }
            }
            
            return true;
        }
        
        return $this->every($this->operatorForWhere(...func_get_args()));
    }
    
    public function except($keys): static
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        
        return new static(Arr::except($this->items, $keys));
    }
    
    public function filter(callable $callback = null): static
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
        }
        
        return new static(array_filter($this->items));
    }
    
    public function first(callable $callback = null, $default = null)
    {
        if (is_null($callback)) {
            if (empty($this->items)) {
                return value($default);
            }
            
            foreach ($this->items as $item) {
                return $item;
            }
        }
        
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }
        
        return value($default);
    }
    
    public function firstWhere($key, $operator = null, $value = null)
    {
        return $this->filter($this->operatorForWhere(...func_get_args()))->first();
    }
    
    public function flatMap(callable $callback): static
    {
        return $this->map($callback)->collapse();
    }
    
    public function flatten($depth = INF): static
    {
        return new static(Arr::flatten($this->items, $depth));
    }
    
    public function flip(): static
    {
        return new static(array_flip($this->items));
    }
    
    public function forget($keys): static
    {
        foreach ((array) $keys as $key) {
            $this->offsetUnset($key);
        }
        
        return $this;
    }
    
    public function forPage(int $page, int $perPage): static
    {
        $offset = max(0, ($page - 1) * $perPage);
        
        return $this->slice($offset, $perPage);
    }
    
    public function get($key, $default = null)
    {
        if ($this->offsetExists($key)) {
            return $this->items[$key];
        }
        
        return value($default);
    }
    
    public function groupBy($groupBy, $preserveKeys = false): static
    {
        if (is_array($groupBy)) {
            $nextGroups = $groupBy;
            $groupBy = array_shift($nextGroups);
        }
        
        $groupBy = $this->valueRetriever($groupBy);
        
        $results = [];
        
        foreach ($this->items as $key => $value) {
            $groupKeys = $groupBy($value, $key);
            
            if (!is_array($groupKeys)) {
                $groupKeys = [$groupKeys];
            }
            
            foreach ($groupKeys as $groupKey) {
                $groupKey = is_bool($groupKey) ? (int) $groupKey : $groupKey;
                
                if (!array_key_exists($groupKey, $results)) {
                    $results[$groupKey] = new static;
                }
                
                $results[$groupKey]->offsetSet($preserveKeys ? $key : null, $value);
            }
        }
        
        $result = new static($results);
        
        if (!empty($nextGroups)) {
            return $result->map(fn($item) => $item->groupBy($nextGroups, $preserveKeys));
        }
        
        return $result;
    }
    
    public function has($key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();
        
        foreach ($keys as $value) {
            if (!$this->offsetExists($value)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function implode($value, $glue = null): string
    {
        if ($this->useAsCallable($value)) {
            return implode($glue ?? '', $this->map($value)->all());
        }
        
        $first = $this->first();
        
        if (is_array($first) || is_object($first)) {
            return implode($glue ?? '', $this->pluck($value)->all());
        }
        
        return implode($value ?? '', $this->items);
    }
    
    public function intersect($items): static
    {
        return new static(array_intersect($this->items, $this->getArrayableItems($items)));
    }
    
    public function intersectByKeys($items): static
    {
        return new static(array_intersect_key($this->items, $this->getArrayableItems($items)));
    }
    
    public function isEmpty(): bool
    {
        return empty($this->items);
    }
    
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }
    
    public function join(string $glue, string $finalGlue = ''): string
    {
        if ($finalGlue === '') {
            return $this->implode($glue);
        }
        
        $count = $this->count();
        
        if ($count === 0) {
            return '';
        }
        
        if ($count === 1) {
            return $this->last();
        }
        
        $collection = new static($this->items);
        
        $finalItem = $collection->pop();
        
        return $collection->implode($glue) . $finalGlue . $finalItem;
    }
    
    public function keyBy($keyBy): static
    {
        $keyBy = $this->valueRetriever($keyBy);
        
        $results = [];
        
        foreach ($this->items as $key => $item) {
            $resolvedKey = $keyBy($item, $key);
            
            if (is_object($resolvedKey)) {
                $resolvedKey = (string) $resolvedKey;
            }
            
            $results[$resolvedKey] = $item;
        }
        
        return new static($results);
    }
    
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }
    
    public function last(callable $callback = null, $default = null)
    {
        if (is_null($callback)) {
            return empty($this->items) ? value($default) : end($this->items);
        }
        
        return $this->filter($callback)->last();
    }
    
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        
        $items = array_map($callback, $this->items, $keys);
        
        return new static(array_combine($keys, $items));
    }
    
    public function mapInto(string $class): static
    {
        return $this->map(fn ($value, $key) => new $class($value, $key));
    }
    
    public function mapToGroups(callable $callback): static
    {
        $groups = $this->map($callback)->reduce(function ($groups, $pair) {
            $groups[key($pair)][] = reset($pair);
            return $groups;
        }, []);
        
        return (new static($groups))->map([$this, 'make']);
    }
    
    public function mapWithKeys(callable $callback): static
    {
        $result = [];
        
        foreach ($this->items as $key => $value) {
            $assoc = $callback($value, $key);
            
            foreach ($assoc as $mapKey => $mapValue) {
                $result[$mapKey] = $mapValue;
            }
        }
        
        return new static($result);
    }
    
    public function max($callback = null)
    {
        $callback = $this->valueRetriever($callback);
        
        return $this->filter(fn ($value) => !is_null($value))->reduce(function ($result, $item) use ($callback) {
            $value = $callback($item);
            
            return is_null($result) || $value > $result ? $value : $result;
        });
    }
    
    public function median($key = null)
    {
        $values = (isset($key) ? $this->pluck($key) : $this)->filter()->sort()->values();
        
        $count = $values->count();
        
        if ($count === 0) {
            return null;
        }
        
        $middle = (int) ($count / 2);
        
        if ($count % 2) {
            return $values->get($middle);
        }
        
        return (new static([
            $values->get($middle - 1), $values->get($middle),
        ]))->average();
    }
    
    public function merge($items): static
    {
        return new static(array_merge($this->items, $this->getArrayableItems($items)));
    }
    
    public function mergeRecursive($items): static
    {
        return new static(array_merge_recursive($this->items, $this->getArrayableItems($items)));
    }
    
    public function min($callback = null)
    {
        $callback = $this->valueRetriever($callback);
        
        return $this->filter(fn ($value) => !is_null($value))->reduce(function ($result, $item) use ($callback) {
            $value = $callback($item);
            
            return is_null($result) || $value < $result ? $value : $result;
        });
    }
    
    public function mode($key = null)
    {
        if ($this->count() === 0) {
            return null;
        }
        
        $collection = isset($key) ? $this->pluck($key) : $this;
        
        $counts = new static;
        
        $collection->each(fn ($value) => $counts[$value] = isset($counts[$value]) ? $counts[$value] + 1 : 1);
        
        $sorted = $counts->sort();
        
        $highestValue = $sorted->last();
        
        return $sorted->filter(fn ($value) => $value == $highestValue)->sort()->keys()->all();
    }
    
    public function nth(int $step, int $offset = 0): static
    {
        $new = [];
        
        $position = 0;
        
        foreach ($this->items as $item) {
            if ($position % $step === $offset) {
                $new[] = $item;
            }
            
            $position++;
        }
        
        return new static($new);
    }
    
    public function only($keys): static
    {
        if (is_null($keys)) {
            return new static($this->items);
        }
        
        $keys = is_array($keys) ? $keys : func_get_args();
        
        return new static(Arr::only($this->items, $keys));
    }
    
    public function pad(int $size, $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }
    
    public function partition($key, $operator = null, $value = null): static
    {
        $passed = [];
        $failed = [];
        
        $callback = func_num_args() === 1
            ? $this->valueRetriever($key)
            : $this->operatorForWhere(...func_get_args());
        
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                $passed[$key] = $item;
            } else {
                $failed[$key] = $item;
            }
        }
        
        return new static([new static($passed), new static($failed)]);
    }
    
    public function pipe(callable $callback)
    {
        return $callback($this);
    }
    
    public function pluck($value, $key = null): static
    {
        return new static(Arr::pluck($this->items, $value, $key));
    }
    
    public function pop()
    {
        return array_pop($this->items);
    }
    
    public function prepend($value, $key = null): static
    {
        if (is_null($key)) {
            array_unshift($this->items, $value);
        } else {
            $this->items = [$key => $value] + $this->items;
        }
        
        return $this;
    }
    
    public function pull($key, $default = null)
    {
        return Arr::pull($this->items, $key, $default);
    }
    
    public function push($value): static
    {
        $this->offsetSet(null, $value);
        return $this;
    }
    
    public function put($key, $value): static
    {
        $this->offsetSet($key, $value);
        return $this;
    }
    
    public function random($number = null)
    {
        if (is_null($number)) {
            return Arr::random($this->items);
        }
        
        return new static(Arr::random($this->items, $number));
    }
    
    public function reduce(callable $callback, $initial = null)
    {
        $result = $initial;
        
        foreach ($this->items as $key => $value) {
            $result = $callback($result, $value, $key);
        }
        
        return $result;
    }
    
    public function reject($callback = true): static
    {
        $useAsCallable = $this->useAsCallable($callback);
        
        return $this->filter(function ($value, $key) use ($callback, $useAsCallable) {
            return $useAsCallable
                ? !$callback($value, $key)
                : $value != $callback;
        });
    }
    
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }
    
    public function search($value, $strict = false)
    {
        if (!$this->useAsCallable($value)) {
            return array_search($value, $this->items, $strict);
        }
        
        foreach ($this->items as $key => $item) {
            if ($value($item, $key)) {
                return $key;
            }
        }
        
        return false;
    }
    
    public function shift()
    {
        return array_shift($this->items);
    }
    
    public function shuffle(): static
    {
        $items = $this->items;
        
        shuffle($items);
        
        return new static($items);
    }
    
    public function slice(int $offset, int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }
    
    public function sort($callback = null): static
    {
        $items = $this->items;
        
        $callback && is_callable($callback)
            ? uasort($items, $callback)
            : asort($items, $callback);
        
        return new static($items);
    }
    
    public function sortBy($callback, $options = SORT_REGULAR, $descending = false): static
    {
        $results = [];
        
        $callback = $this->valueRetriever($callback);
        
        foreach ($this->items as $key => $value) {
            $results[$key] = $callback($value, $key);
        }
        
        $descending ? arsort($results, $options)
                    : asort($results, $options);
        
        foreach (array_keys($results) as $key) {
            $results[$key] = $this->items[$key];
        }
        
        return new static($results);
    }
    
    public function sortByDesc($callback, $options = SORT_REGULAR): static
    {
        return $this->sortBy($callback, $options, true);
    }
    
    public function sortKeys($options = SORT_REGULAR, $descending = false): static
    {
        $items = $this->items;
        
        $descending ? krsort($items, $options) : ksort($items, $options);
        
        return new static($items);
    }
    
    public function sortKeysDesc($options = SORT_REGULAR): static
    {
        return $this->sortKeys($options, true);
    }
    
    public function splice($offset, $length = null, $replacement = []): static
    {
        if (func_num_args() === 1) {
            return new static(array_splice($this->items, $offset));
        }
        
        return new static(array_splice($this->items, $offset, $length, $replacement));
    }
    
    public function split($numberOfGroups): static
    {
        if ($this->isEmpty()) {
            return new static;
        }
        
        $groups = new static;
        
        $groupSize = floor($this->count() / $numberOfGroups);
        
        $remain = $this->count() % $numberOfGroups;
        
        $start = 0;
        
        for ($i = 0; $i < $numberOfGroups; $i++) {
            $size = $groupSize;
            
            if ($i < $remain) {
                $size++;
            }
            
            if ($size) {
                $groups->push(new static(array_slice($this->items, $start, $size)));
                
                $start += $size;
            }
        }
        
        return $groups;
    }
    
    public function sum($callback = null)
    {
        if (is_null($callback)) {
            return array_sum($this->items);
        }
        
        $callback = $this->valueRetriever($callback);
        
        return $this->reduce(function ($result, $item) use ($callback) {
            return $result + $callback($item);
        }, 0);
    }
    
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }
        
        return $this->slice(0, $limit);
    }
    
    public function tap(callable $callback): static
    {
        $callback(new static($this->items));
        
        return $this;
    }
    
    public function times(int $number, callable $callback = null): static
    {
        if ($number < 1) {
            return new static;
        }
        
        if (is_null($callback)) {
            return new static(range(1, $number));
        }
        
        return (new static(range(1, $number)))->map($callback);
    }
    
    public function toArray(): array
    {
        return array_map(function ($value) {
            return $value instanceof self ? $value->toArray() : $value;
        }, $this->items);
    }
    
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }
    
    public function transform(callable $callback): static
    {
        $this->items = $this->map($callback)->all();
        
        return $this;
    }
    
    public function union($items): static
    {
        return new static($this->items + $this->getArrayableItems($items));
    }
    
    public function unique($key = null, $strict = false): static
    {
        if (is_null($key)) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }
        
        $key = $this->valueRetriever($key);
        
        $exists = [];
        
        return $this->reject(function ($item, $key) use (&$exists, $strict) {
            if (in_array($id = $key($item, $key), $exists, $strict)) {
                return true;
            }
            
            $exists[] = $id;
        });
    }
    
    public function values(): static
    {
        return new static(array_values($this->items));
    }
    
    public function when($value, callable $callback, callable $default = null): static
    {
        if ($value) {
            return $callback($this, $value);
        } elseif ($default) {
            return $default($this, $value);
        }
        
        return $this;
    }
    
    public function whenEmpty(callable $callback, callable $default = null): static
    {
        return $this->when($this->isEmpty(), $callback, $default);
    }
    
    public function whenNotEmpty(callable $callback, callable $default = null): static
    {
        return $this->when($this->isNotEmpty(), $callback, $default);
    }
    
    public function where($key, $operator = null, $value = null): static
    {
        return $this->filter($this->operatorForWhere(...func_get_args()));
    }
    
    public function whereBetween($key, array $values): static
    {
        return $this->filter(function ($item) use ($key, $values) {
            $value = data_get($item, $key);
            
            return $value >= $values[0] && $value <= $values[1];
        });
    }
    
    public function whereIn($key, $values, $strict = false): static
    {
        $values = $this->getArrayableItems($values);
        
        return $this->filter(function ($item) use ($key, $values, $strict) {
            return in_array(data_get($item, $key), $values, $strict);
        });
    }
    
    public function whereInstanceOf($type): static
    {
        return $this->filter(function ($value) use ($type) {
            return $value instanceof $type;
        });
    }
    
    public function whereNotBetween($key, array $values): static
    {
        return $this->reject(function ($item) use ($key, $values) {
            $value = data_get($item, $key);
            
            return $value >= $values[0] && $value <= $values[1];
        });
    }
    
    public function whereNotIn($key, $values, $strict = false): static
    {
        $values = $this->getArrayableItems($values);
        
        return $this->reject(function ($item) use ($key, $values, $strict) {
            return in_array(data_get($item, $key), $values, $strict);
        });
    }
    
    public function wrap($value): static
    {
        return $value instanceof self
            ? $value
            : new static(Arr::wrap($value));
    }
    
    public function zip($items): static
    {
        $arrayableItems = array_map(fn ($items) => $this->getArrayableItems($items), func_get_args());
        
        $params = array_merge([fn () => new static(func_get_args()), $this->items], $arrayableItems);
        
        return new static(array_map(...$params));
    }
    
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
    
    public function jsonSerialize(): array
    {
        return array_map(function ($value) {
            if ($value instanceof JsonSerializable) {
                return $value->jsonSerialize();
            } elseif ($value instanceof self) {
                return $value->toArray();
            }
            
            return $value;
        }, $this->items);
    }
    
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }
    
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }
    
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }
    
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
    
    protected function getArrayableItems($items): array
    {
        if (is_array($items)) {
            return $items;
        } elseif ($items instanceof self) {
            return $items->all();
        } elseif ($items instanceof JsonSerializable) {
            return $items->jsonSerialize();
        } elseif ($items instanceof Traversable) {
            return iterator_to_array($items);
        }
        
        return (array) $items;
    }
    
    protected function valueRetriever($value)
    {
        if ($this->useAsCallable($value)) {
            return $value;
        }
        
        return fn ($item) => data_get($item, $value);
    }
    
    protected function useAsCallable($value): bool
    {
        return !is_string($value) && is_callable($value);
    }
    
    protected function operatorForWhere($key, $operator = null, $value = null)
    {
        if (func_num_args() === 1) {
            $value = true;
            $operator = '=';
        }
        
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        
        return function ($item) use ($key, $operator, $value) {
            $retrieved = data_get($item, $key);
            
            $strings = array_filter([$retrieved, $value], function ($value) {
                return is_string($value) || (is_object($value) && method_exists($value, '__toString'));
            });
            
            if (count($strings) < 2 && count(array_filter([$retrieved, $value], 'is_object')) == 1) {
                return in_array($operator, ['!=', '<>', '!==']);
            }
            
            switch ($operator) {
                default:
                case '=':
                case '==':  return $retrieved == $value;
                case '!=':
                case '<>':  return $retrieved != $value;
                case '<':   return $retrieved < $value;
                case '>':   return $retrieved > $value;
                case '<=':  return $retrieved <= $value;
                case '>=':  return $retrieved >= $value;
                case '===': return $retrieved === $value;
                case '!==': return $retrieved !== $value;
            }
        };
    }
    
    public function __toString(): string
    {
        return $this->toJson();
    }
}