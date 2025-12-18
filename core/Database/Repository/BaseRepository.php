<?php
// core/Database/Repository/BaseRepository.php

namespace Apollo\Core\Database\Repository;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Database\QueryBuilder;
use Apollo\Core\Http\Request;

abstract class BaseRepository
{
    protected QueryBuilder $queryBuilder;
    protected string $table;

    public function __construct()
    {
        $this->queryBuilder = new QueryBuilder(
            DatabaseManager::getConnection()
        );

        if (empty($this->table)) {
            $className = (new \ReflectionClass($this))->getShortName();
            $this->table = strtolower(str_replace('Repository', '', $className)) . 's';
        }
    }

    protected function builder(): QueryBuilder
    {
        return $this->queryBuilder->table($this->table);
    }

    public function all(): array
    {
        return $this->builder()->get();
    }

    public function find(int $id): ?array
    {
        return $this->builder()->where('id', $id)->first();
    }

    public function create(array $data): ?string
    {
        return $this->builder()->insert($data);
    }

    public function update(int $id, array $data): int
    {
        return $this->builder()->where('id', $id)->update($data);
    }

    public function delete(int $id): int
    {
        return $this->builder()->where('id', $id)->delete();
    }

    public function findBy(string $field, $value): ?array
    {
        return $this->builder()->where($field, $value)->first();
    }

    public function findWhere(array $conditions): ?array
    {
        $query = $this->builder();

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $query->first();
    }

    public function getWhere(array $conditions): array
    {
        $query = $this->builder();

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $query->get();
    }

    public function query(): QueryBuilder
    {
        return $this->builder();
    }

    public function search(string $term): array
    {
        if (empty($term)) {
            return [];
        }

        $searchableFields = $this->getSearchableFields();

        if (empty($searchableFields)) {
            return [];
        }

        $query = $this->builder();

        // Agregar la primera condición como WHERE
        $firstField = array_shift($searchableFields);
        $query->where($firstField, 'LIKE', "%{$term}%");

        // Agregar el resto como OR WHERE
        foreach ($searchableFields as $field) {
            $query->orWhere($field, 'LIKE', "%{$term}%");
        }

        return $query->get();
    }

    protected function getSearchableFields(): array
    {
        return property_exists($this, 'searchable') ? $this->searchable : [];
    }

    public function advancedSearch(string $term, array $options = []): array
    {
        if (empty($term)) {
            return [];
        }

        $searchableFields = $this->getSearchableFields();

        if (empty($searchableFields)) {
            return [];
        }

        // Permitir búsqueda solo en campos específicos
        if (isset($options['fields']) && is_array($options['fields'])) {
            $searchableFields = array_intersect($searchableFields, $options['fields']);
        }

        if (empty($searchableFields)) {
            return [];
        }

        $query = $this->builder();

        // Tipo de búsqueda: exact, starts_with, ends_with, contains (default)
        $searchType = $options['type'] ?? 'contains';
        $searchPattern = $this->getSearchPattern($term, $searchType);

        // Agregar la primera condición como WHERE
        $firstField = array_shift($searchableFields);
        $query->where($firstField, 'LIKE', $searchPattern);

        // Agregar el resto como OR WHERE
        foreach ($searchableFields as $field) {
            $query->orWhere($field, 'LIKE', $searchPattern);
        }

        // Agregar ordenamiento si se especifica
        if (isset($options['order_by'])) {
            $direction = $options['order_direction'] ?? 'ASC';
            $query->orderBy($options['order_by'], $direction);
        }

        // Agregar límite si se especifica
        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        return $query->get();
    }

    private function getSearchPattern(string $term, string $type): string
    {
        return match ($type) {
            'exact' => $term,
            'starts_with' => "{$term}%",
            'ends_with' => "%{$term}",
            'contains' => "%{$term}%",
            default => "%{$term}%"
        };
    }

    public function paginate(?int $perPage = 15, ?int $page = 1): array
    {  
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