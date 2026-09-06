<?php

namespace Apollo\Core\Database;

use Apollo\Core\Database\Connection\DatabaseManager;

class Blueprint
{
    private string $table;
    private array $columns = [];
    private array $indexes = [];
    private array $tableIndexes = []; // SQLite: índices que se crean aparte
    private string $driver;

    public function __construct(string $table)
    {
        $this->table = $table;
        // El driver se lee de DatabaseManager para emitir DDL compatible
        $this->driver = DatabaseManager::driver();
    }

    private function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /**
     * Add auto-incrementing ID column
     */
    public function id(string $name = 'id'): self
    {
        $this->columns[] = $this->isSqlite()
            ? "`{$name}` INTEGER PRIMARY KEY AUTOINCREMENT"
            : "`{$name}` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY";
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
     * Add enum column (SQLite: TEXT)
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        if ($this->isSqlite()) {
            $column = new ColumnDefinition($name, "TEXT");
        } else {
            $valuesList = "'" . implode("','", $values) . "'";
            $column = new ColumnDefinition($name, "ENUM({$valuesList})");
        }
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add JSON column (SQLite: TEXT)
     */
    public function json(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $this->isSqlite() ? "TEXT" : "JSON");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add timestamp column (SQLite: DATETIME)
     */
    public function timestamp(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $this->isSqlite() ? "DATETIME" : "TIMESTAMP");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add timestamps (created_at, updated_at)
     */
    public function timestamps(): self
    {
        if ($this->isSqlite()) {
            // SQLite no soporta ON UPDATE en defaults
            $this->columns[] = "`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP";
            $this->columns[] = "`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP";
        } else {
            $this->columns[] = "`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
            $this->columns[] = "`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        }
        return $this;
    }

    /**
     * Add soft deletes (deleted_at)
     */
    public function softDeletes(): self
    {
        $this->columns[] = $this->isSqlite()
            ? "`deleted_at` DATETIME NULL DEFAULT NULL"
            : "`deleted_at` TIMESTAMP NULL DEFAULT NULL";
        return $this;
    }

    /**
     * Add foreign key column (SQLite: INTEGER para emparejar con id INTEGER)
     */
    public function foreignId(string $name): ForeignKeyDefinition
    {
        $column = new ForeignKeyDefinition($name, $this->isSqlite() ? "INTEGER" : "BIGINT UNSIGNED");
        $column->setTableName($this->table);
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add index (SQLite: se crea aparte con CREATE INDEX)
     */
    public function index($columns, ?string $name = null): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        $indexName = $name ?? $this->table . '_' . implode('_', $columns) . '_index';
        $columnsList = '`' . implode('`, `', $columns) . '`';

        if ($this->isSqlite()) {
            $this->tableIndexes[] = "CREATE INDEX IF NOT EXISTS `{$indexName}` ON `{$this->table}` ({$columnsList})";
        } else {
            $this->indexes[] = "INDEX `{$indexName}` ({$columnsList})";
        }

        return $this;
    }

    /**
     * Add unique index (SQLite: constraint UNIQUE con nombre)
     */
    public function unique($columns, ?string $name = null): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        $indexName = $name ?? $this->table . '_' . implode('_', $columns) . '_unique';
        $columnsList = '`' . implode('`, `', $columns) . '`';

        $this->indexes[] = $this->isSqlite()
            ? "CONSTRAINT `{$indexName}` UNIQUE ({$columnsList})"
            : "UNIQUE KEY `{$indexName}` ({$columnsList})";

        return $this;
    }

    /**
     * SQL adicional por driver (SQLite: CREATE INDEX aparte)
     */
    public function getAdditionalIndexSql(): array
    {
        return $this->tableIndexes;
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

        if ($this->isSqlite()) {
            return "CREATE TABLE `{$this->table}` (\n    {$constraintsList}\n)";
        }

        return "CREATE TABLE `{$this->table}` (\n    {$constraintsList}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
}