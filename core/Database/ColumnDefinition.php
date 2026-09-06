<?php

namespace Apollo\Core\Database;

class ColumnDefinition
{
    protected string $name;
    protected string $type;
    protected bool $nullable = false;
    protected bool $primary = false;
    protected $default = null;
    protected bool $hasDefault = false;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    /**
     * Make column the primary key (string/composite PKs)
     */
    public function primary(): self
    {
        $this->primary = true;
        return $this;
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

        if ($this->primary) {
            $sql .= " PRIMARY KEY";
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