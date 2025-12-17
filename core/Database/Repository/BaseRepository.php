<?php
// core/Database/Repository/BaseRepository.php

namespace Apollo\Core\Database\Repository;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Database\QueryBuilder;

abstract class BaseRepository {
    protected QueryBuilder $queryBuilder;
    protected string $table;
    
    public function __construct() {
        $this->queryBuilder = new QueryBuilder(
            DatabaseManager::getConnection()
        );
        
        if (empty($this->table)) {
            $className = (new \ReflectionClass($this))->getShortName();
            $this->table = strtolower(str_replace('Repository', '', $className)) . 's';
        }
    }
    
    protected function builder(): QueryBuilder {
        return $this->queryBuilder->table($this->table);
    }
    
    public function all(): array {
        return $this->builder()->get();
    }
    
    public function find(int $id): ?array {
        return $this->builder()->where('id', $id)->first();
    }
    
    public function create(array $data): ?string {
        return $this->builder()->insert($data);
    }
    
    public function update(int $id, array $data): int {
        return $this->builder()->where('id', $id)->update($data);
    }
    
    public function delete(int $id): int {
        return $this->builder()->where('id', $id)->delete();
    }
    
    public function paginate(int $perPage = 15, int $page = 1): array {
        $offset = ($page - 1) * $perPage;
        $total = $this->builder()->count();
        
        $items = $this->builder()
            ->limit($perPage)
            ->offset($offset)
            ->get();
        
        return [
            'data' => $items,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage)
            ]
        ];
    }
}