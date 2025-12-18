<?php

namespace Apollo\Core\Database;

use PDO;
use PDOStatement;

class QueryBuilder {
    private PDO $pdo;
    private array $bindings = [];
    private array $queryParts = [
        'select' => '*',
        'from' => '',
        'where' => [],
        'order' => '',
        'limit' => '',
        'offset' => ''
    ];
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    public function table(string $table): self {
        $this->queryParts['from'] = $table;
        return $this;
    }
    
    public function select($columns = '*'): self {
        if (is_array($columns)) {
            $this->queryParts['select'] = implode(', ', $columns);
        } else {
            $this->queryParts['select'] = $columns;
        }
        return $this;
    }
    
    public function where(string $column, $operator, $value = null): self {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        
        $placeholder = 'param_' . count($this->bindings);
        $this->queryParts['where'][] = "{$column} {$operator} :{$placeholder}";
        $this->bindings[$placeholder] = $value;
        
        return $this;
    }
    
    public function orWhere(string $column, $operator, $value = null): self {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        
        $placeholder = 'param_' . count($this->bindings);
        $this->queryParts['where'][] = "OR {$column} {$operator} :{$placeholder}";
        $this->bindings[$placeholder] = $value;
        
        return $this;
    }
    
    public function orderBy(string $column, string $direction = 'ASC'): self {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->queryParts['order'] = "ORDER BY {$column} {$direction}";
        return $this;
    }
    
    public function limit(int $limit): self {
        $this->queryParts['limit'] = "LIMIT {$limit}";
        return $this;
    }
    
    public function offset(int $offset): self {
        $this->queryParts['offset'] = "OFFSET {$offset}";
        return $this;
    }
    
    private function buildQuery(): string {
        $sql = "SELECT {$this->queryParts['select']} FROM {$this->queryParts['from']}";
        
        if (!empty($this->queryParts['where'])) {
            $whereClause = implode(' ', $this->queryParts['where']);
            // Si el primer where empieza con OR, lo convertimos a WHERE normal
            if (stripos($whereClause, 'OR ') === 0) {
                $whereClause = substr($whereClause, 3);
            }
            $sql .= " WHERE {$whereClause}";
        }
        
        if ($this->queryParts['order']) {
            $sql .= " {$this->queryParts['order']}";
        }
        
        if ($this->queryParts['limit']) {
            $sql .= " {$this->queryParts['limit']}";
        }
        
        if ($this->queryParts['offset']) {
            $sql .= " {$this->queryParts['offset']}";
        }
        
        return $sql;
    }
    
    public function get(): array {
        $sql = $this->buildQuery();
        $stmt = $this->execute($sql);
        
        $this->reset();
        return $stmt->fetchAll();
    }
    
    public function first(): ?array {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? null;
    }
    
    public function count(): int {
        // Create a separate count query without affecting current query parts
        $sql = "SELECT COUNT(*) as total FROM {$this->queryParts['from']}";
        
        if (!empty($this->queryParts['where'])) {
            $whereClause = implode(' ', $this->queryParts['where']);
            // Si el primer where empieza con OR, lo convertimos a WHERE normal
            if (stripos($whereClause, 'OR ') === 0) {
                $whereClause = substr($whereClause, 3);
            }
            $sql .= " WHERE {$whereClause}";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $result = $stmt->fetch();
        
        return (int) ($result['total'] ?? 0);
    }
    
    public function insert(array $data): ?string {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$this->queryParts['from']} ({$columns}) VALUES ({$placeholders})";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        
        return $this->pdo->lastInsertId();
    }
    
    public function update(array $data): int {
        $setParts = [];
        foreach ($data as $key => $value) {
            $setParts[] = "{$key} = :{$key}";
            $this->bindings[$key] = $value;
        }
        
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE {$this->queryParts['from']} SET {$setClause}";
        
        if (!empty($this->queryParts['where'])) {
            $whereClause = implode(' ', $this->queryParts['where']);
            $sql .= " WHERE {$whereClause}";
        }
        
        $stmt = $this->execute($sql);
        $this->reset();
        
        return $stmt->rowCount();
    }
    
    public function delete(): int {
        $sql = "DELETE FROM {$this->queryParts['from']}";
        
        if (!empty($this->queryParts['where'])) {
            $whereClause = implode(' ', $this->queryParts['where']);
            $sql .= " WHERE {$whereClause}";
        }
        
        $stmt = $this->execute($sql);
        $this->reset();
        
        return $stmt->rowCount();
    }
    
    private function execute(string $sql): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt;
    }
    
    private function reset(): void {
        $this->queryParts = [
            'select' => '*',
            'from' => '',
            'where' => [],
            'order' => '',
            'limit' => '',
            'offset' => ''
        ];
        $this->bindings = [];
    }
    
    public function raw(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}