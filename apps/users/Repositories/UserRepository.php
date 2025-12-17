<?php
// apps/users/Repositories/UserRepository.php

namespace Apps\Users\Repositories;

class UserRepository {
    private $connection;
    
    public function __construct($connection) {
        $this->connection = $connection;
    }
    
    public function all(): array {
        // Para pruebas, retornar datos dummy
        return [
            ['id' => 1, 'name' => 'Repository User 1', 'email' => 'repo1@example.com'],
            ['id' => 2, 'name' => 'Repository User 2', 'email' => 'repo2@example.com'],
        ];
    }
    
    public function find(int $id): ?array {
        $users = $this->all();
        
        foreach ($users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        
        return null;
    }
    
    public function create(array $data): array {
        // Simular creación
        return array_merge(['id' => rand(100, 999)], $data);
    }
}