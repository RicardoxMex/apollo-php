<?php

namespace Apollo\Core\Database;

use Apollo\Core\Database\Connection\DatabaseManager;
use PDO;

class Schema
{
    private static function getConnection(): PDO
    {
        return DatabaseManager::getConnection();
    }

    /**
     * Create a new table
     */
    public static function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->toSql();
        self::getConnection()->exec($sql);
    }

    /**
     * Drop table if exists
     */
    public static function dropIfExists(string $table): void
    {
        $sql = "DROP TABLE IF EXISTS `{$table}`";
        self::getConnection()->exec($sql);
    }

    /**
     * Check if table exists
     */
    public static function hasTable(string $table): bool
    {
        $sql = "SHOW TABLES LIKE '{$table}'";
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}

class Blueprint
{
    private string $table;
    private array $columns = [];
    private array $indexes = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * Add auto-incrementing ID column
     */
    public function id(string $name = 'id'): self
    {
        $this->columns[] = "`{$name}` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY";
        return $this;
    }

    /**
     * Add string column
     */
    public function string(string $name, int $length = 255): ColumnDefinition
    {
        $column = new ColumnDefinition($name, "VARCHAR({$length})");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add text column
     */
    public function text(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, "TEXT");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add integer column
     */
    public function integer(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, "INT");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add boolean column
     */
    public function boolean(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, "BOOLEAN");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add enum column
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        $valuesList = "'" . implode("','", $values) . "'";
        $column = new ColumnDefinition($name, "ENUM({$valuesList})");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add JSON column
     */
    public function json(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, "JSON");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add timestamp column
     */
    public function timestamp(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, "TIMESTAMP");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add timestamps (created_at, updated_at)
     */
    public function timestamps(): self
    {
        $this->columns[] = "`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
        $this->columns[] = "`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        return $this;
    }

    /**
     * Add soft deletes (deleted_at)
     */
    public function softDeletes(): self
    {
        $this->columns[] = "`deleted_at` TIMESTAMP NULL DEFAULT NULL";
        return $this;
    }

    /**
     * Add foreign key column
     */
    public function foreignId(string $name): ForeignKeyDefinition
    {
        $column = new ForeignKeyDefinition($name, "BIGINT UNSIGNED");
        $column->setTableName($this->table);
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add index
     */
    public function index($columns, ?string $name = null): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        
        $indexName = $name ?? $this->table . '_' . implode('_', $columns) . '_index';
        $columnsList = '`' . implode('`, `', $columns) . '`';
        $this->indexes[] = "INDEX `{$indexName}` ({$columnsList})";
        
        return $this;
    }

    /**
     * Add unique index
     */
    public function unique($columns, ?string $name = null): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        
        $indexName = $name ?? $this->table . '_' . implode('_', $columns) . '_unique';
        $columnsList = '`' . implode('`, `', $columns) . '`';
        $this->indexes[] = "UNIQUE KEY `{$indexName}` ({$columnsList})";
        
        return $this;
    }

    /**
     * Generate SQL
     */
    public function toSql(): string
    {
        $columns = [];
        $foreignKeys = [];

        foreach ($this->columns as $column) {
            if ($column instanceof ForeignKeyDefinition) {
                $columns[] = $column->toSql();
                if ($column->hasConstraint()) {
                    $foreignKeys[] = $column->getConstraintSql();
                }
            } elseif ($column instanceof ColumnDefinition) {
                $columns[] = $column->toSql();
            } else {
                $columns[] = $column;
            }
        }

        $allConstraints = array_merge($columns, $this->indexes, $foreignKeys);
        $constraintsList = implode(",\n    ", $allConstraints);

        return "CREATE TABLE `{$this->table}` (\n    {$constraintsList}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
}

class ColumnDefinition
{
    protected string $name;
    protected string $type;
    protected bool $nullable = false;
    protected $default = null;
    protected bool $hasDefault = false;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    /**
     * Make column nullable
     */
    public function nullable(): self
    {
        $this->nullable = true;
        return $this;
    }

    /**
     * Set default value
     */
    public function default($value): self
    {
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    /**
     * Make column unique
     */
    public function unique(): self
    {
        $this->type .= " UNIQUE";
        return $this;
    }

    /**
     * Use current timestamp as default
     */
    public function useCurrent(): self
    {
        $this->default = 'CURRENT_TIMESTAMP';
        $this->hasDefault = true;
        return $this;
    }

    /**
     * Generate SQL
     */
    public function toSql(): string
    {
        $sql = "`{$this->name}` {$this->type}";
        
        if (!$this->nullable) {
            $sql .= " NOT NULL";
        } else {
            $sql .= " NULL";
        }
        
        if ($this->hasDefault) {
            if (is_null($this->default)) {
                $sql .= " DEFAULT NULL";
            } elseif (is_bool($this->default)) {
                $sql .= " DEFAULT " . ($this->default ? '1' : '0');
            } elseif (is_string($this->default) && in_array(strtoupper($this->default), ['CURRENT_TIMESTAMP', 'NOW()'])) {
                $sql .= " DEFAULT {$this->default}";
            } elseif (is_string($this->default)) {
                $sql .= " DEFAULT '{$this->default}'";
            } else {
                $sql .= " DEFAULT {$this->default}";
            }
        }
        
        return $sql;
    }
}

class ForeignKeyDefinition extends ColumnDefinition
{
    private ?string $references = null;
    private ?string $on = null;
    private string $onDelete = 'RESTRICT';
    private string $onUpdate = 'RESTRICT';
    private ?string $tableName = null;

    /**
     * Set foreign key constraint
     */
    public function constrained(?string $table = null, string $column = 'id'): self
    {
        $this->references = $column;
        if ($table) {
            $this->on = $table;
        } else {
            // Convertir user_id -> users, role_id -> roles, etc.
            $baseName = str_replace('_id', '', $this->name);
            $this->on = $baseName . 's';
        }
        return $this;
    }

    /**
     * Set on delete action
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    /**
     * Set on update action
     */
    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    /**
     * Set table name for unique constraint naming
     */
    public function setTableName(string $tableName): void
    {
        $this->tableName = $tableName;
    }

    /**
     * Check if has constraint
     */
    public function hasConstraint(): bool
    {
        return !is_null($this->references) && !is_null($this->on);
    }

    /**
     * Get constraint SQL
     */
    public function getConstraintSql(): string
    {
        if (!$this->hasConstraint()) {
            return '';
        }

        // Generar un nombre único para la constraint incluyendo la tabla origen
        $constraintName = "fk_" . $this->tableName . "_" . $this->name . "_" . $this->on;
        return "CONSTRAINT `{$constraintName}` FOREIGN KEY (`{$this->name}`) REFERENCES `{$this->on}` (`{$this->references}`) ON DELETE {$this->onDelete} ON UPDATE {$this->onUpdate}";
    }
}

