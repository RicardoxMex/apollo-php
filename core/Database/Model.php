<?php

namespace Apollo\Core\Database;

use Apollo\Core\Database\QueryBuilder;
use Apollo\Core\Database\Connection\DatabaseManager;

abstract class Model
{
    protected $table;
    protected $primaryKey = 'id';
    protected $fillable = [];
    protected $hidden = [];
    protected $casts = [];
    protected $dates = [];
    public $attributes = [];
    public $original = [];
    public $exists = false;
    
    private static $connection;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Get database connection
     */
    public static function getConnection()
    {
        if (!self::$connection) {
            $manager = new DatabaseManager();
            self::$connection = $manager->getConnection();
        }
        return self::$connection;
    }

    /**
     * Get table name
     */
    public function getTable(): string
    {
        return $this->table ?? strtolower(class_basename(static::class)) . 's';
    }

    /**
     * Fill model with attributes
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * Check if attribute is fillable
     */
    protected function isFillable(string $key): bool
    {
        return in_array($key, $this->fillable) || empty($this->fillable);
    }

    /**
     * Set attribute value
     */
    public function setAttribute(string $key, $value): void
    {
        // Check for mutator
        $mutator = 'set' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $this->$mutator($value);
            return;
        }

        $this->attributes[$key] = $value;
    }

    /**
     * Get attribute value
     */
    public function getAttribute(string $key)
    {
        // Check for accessor
        $accessor = 'get' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor();
        }

        $value = $this->attributes[$key] ?? null;

        // Apply casting
        if (isset($this->casts[$key])) {
            return $this->castAttribute($key, $value);
        }

        return $value;
    }

    /**
     * Cast attribute to specified type
     */
    protected function castAttribute(string $key, $value)
    {
        $castType = $this->casts[$key];

        if (is_null($value)) {
            return null;
        }

        switch ($castType) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'array':
                return is_string($value) ? json_decode($value, true) : $value;
            case 'json':
                return is_string($value) ? json_decode($value, true) : $value;
            case 'datetime':
                return is_string($value) ? new \DateTime($value) : $value;
            default:
                return $value;
        }
    }

    /**
     * Magic getter
     */
    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Magic setter
     */
    public function __set(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Magic isset
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        $attributes = $this->attributes;

        // Remove hidden attributes
        foreach ($this->hidden as $hidden) {
            unset($attributes[$hidden]);
        }

        // Apply accessors
        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->getAttribute($key);
        }

        return $attributes;
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Create new query builder instance
     */
    public static function query(): QueryBuilder
    {
        $instance = new static();
        $queryBuilder = new QueryBuilder(self::getConnection(), $instance->getTable(), static::class);
        return $queryBuilder;
    }

    /**
     * Find model by ID
     */
    public static function find($id): ?static
    {
        $result = static::query()->where('id', $id)->first();
        
        if (!$result) {
            return null;
        }

        $instance = new static();
        $instance->attributes = $result;
        $instance->original = $result;
        $instance->exists = true;

        return $instance;
    }

    /**
     * Find all models
     */
    public static function all(): array
    {
        $results = static::query()->get();
        $models = [];

        foreach ($results as $result) {
            $instance = new static();
            $instance->attributes = $result;
            $instance->original = $result;
            $instance->exists = true;
            $models[] = $instance;
        }

        return $models;
    }

    /**
     * Create new model
     */
    public static function create(array $attributes): static
    {
        $instance = new static($attributes);
        $instance->save();
        return $instance;
    }

    /**
     * Save model
     */
    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        } else {
            return $this->performInsert();
        }
    }

    /**
     * Update model
     */
    public function update(array $attributes): bool
    {
        $this->fill($attributes);
        return $this->save();
    }

    /**
     * Delete model
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $result = static::query()
            ->where($this->primaryKey, $this->getAttribute($this->primaryKey))
            ->delete();

        if ($result) {
            $this->exists = false;
        }

        return $result;
    }

    /**
     * Perform insert
     */
    protected function performInsert(): bool
    {
        $attributes = $this->prepareAttributesForDatabase($this->attributes);
        
        // Add timestamps
        if (in_array('created_at', $this->fillable) || empty($this->fillable)) {
            $attributes['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $this->fillable) || empty($this->fillable)) {
            $attributes['updated_at'] = date('Y-m-d H:i:s');
        }

        $id = static::query()->insert($attributes);
        
        if ($id) {
            $this->setAttribute($this->primaryKey, $id);
            $this->exists = true;
            $this->original = $this->attributes;
            return true;
        }

        return false;
    }

    /**
     * Perform update
     */
    protected function performUpdate(): bool
    {
        $attributes = $this->getDirty();
        
        if (empty($attributes)) {
            return true;
        }

        // Add updated_at timestamp
        if (in_array('updated_at', $this->fillable) || empty($this->fillable)) {
            $attributes['updated_at'] = date('Y-m-d H:i:s');
        }

        $attributes = $this->prepareAttributesForDatabase($attributes);

        $result = static::query()
            ->where($this->primaryKey, $this->getAttribute($this->primaryKey))
            ->update($attributes);

        if ($result) {
            $this->original = $this->attributes;
        }

        return $result;
    }

    /**
     * Get dirty attributes
     */
    protected function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Prepare attributes for database storage
     */
    protected function prepareAttributesForDatabase(array $attributes): array
    {
        $prepared = [];

        foreach ($attributes as $key => $value) {
            // Handle casting for database storage
            if (isset($this->casts[$key])) {
                $castType = $this->casts[$key];
                
                switch ($castType) {
                    case 'array':
                    case 'json':
                        $prepared[$key] = is_array($value) ? json_encode($value) : $value;
                        break;
                    case 'boolean':
                    case 'bool':
                        $prepared[$key] = $value ? 1 : 0;
                        break;
                    default:
                        $prepared[$key] = $value;
                        break;
                }
            } else {
                $prepared[$key] = $value;
            }
        }

        return $prepared;
    }

    /**
     * Add where clause
     */
    public static function where(string $column, $operator = null, $value = null): QueryBuilder
    {
        $query = static::query();
        
        // Pasar solo los argumentos que fueron proporcionados
        if (func_num_args() === 2) {
            return $query->where($column, $operator);
        } else {
            return $query->where($column, $operator, $value);
        }
    }

    /**
     * Add orWhere clause
     */
    public static function orWhere(string $column, $operator = null, $value = null): QueryBuilder
    {
        $query = static::query();
        
        // Pasar solo los argumentos que fueron proporcionados
        if (func_num_args() === 2) {
            return $query->orWhere($column, $operator);
        } else {
            return $query->orWhere($column, $operator, $value);
        }
    }

    /**
     * Get first result
     */
    public static function first(): ?static
    {
        $result = static::query()->first();
        
        if (!$result) {
            return null;
        }

        $instance = new static();
        $instance->attributes = $result;
        $instance->original = $result;
        $instance->exists = true;

        return $instance;
    }

    /**
     * Get results
     */
    public static function get(): array
    {
        $results = static::query()->get();
        $models = [];

        foreach ($results as $result) {
            $instance = new static();
            $instance->attributes = $result;
            $instance->original = $result;
            $instance->exists = true;
            $models[] = $instance;
        }

        return $models;
    }

    /**
     * Simple relationships - hasMany
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null)
    {
        $foreignKey = $foreignKey ?? strtolower(class_basename(static::class)) . '_id';
        $localKey = $localKey ?? $this->primaryKey;

        return $related::where($foreignKey, $this->getAttribute($localKey))->get();
    }

    /**
     * Simple relationships - belongsTo
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null)
    {
        $foreignKey = $foreignKey ?? strtolower(class_basename($related)) . '_id';
        $ownerKey = $ownerKey ?? 'id';

        return $related::where($ownerKey, $this->getAttribute($foreignKey))->first();
    }

    /**
     * Simple relationships - belongsToMany (basic implementation)
     */
    public function belongsToMany(string $related, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null)
    {
        // This is a simplified implementation
        // In a real ORM, this would be much more complex
        $table = $table ?? $this->getTable() . '_' . (new $related())->getTable();
        $foreignPivotKey = $foreignPivotKey ?? strtolower(class_basename(static::class)) . '_id';
        $relatedPivotKey = $relatedPivotKey ?? strtolower(class_basename($related)) . '_id';

        // Get pivot records
        $pivots = static::query()
            ->table($table)
            ->where($foreignPivotKey, $this->getAttribute($this->primaryKey))
            ->get();

        $relatedIds = array_column($pivots, $relatedPivotKey);
        
        if (empty($relatedIds)) {
            return [];
        }

        // Get related models
        $related = $related::query()->whereIn('id', $relatedIds)->get();
        
        // Add pivot data to models
        foreach ($related as $model) {
            $pivot = array_filter($pivots, function($p) use ($model, $relatedPivotKey) {
                return $p[$relatedPivotKey] == $model->getAttribute('id');
            });
            $model->pivot = reset($pivot);
        }

        return $related;
    }
}