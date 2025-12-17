<?php
// apps/users/Services/UserService.php

namespace Apps\Users\Services;

use Apps\Users\Models\User;

class UserService {
    private User $user;
    
    public function __construct() {
        $this->user = new User();
    }

    public function paginate(int $perPage, int $page): array {
        return $this->user->paginate($perPage, $page);
    }
    
    public function getAllUsers(): array {
        return $this->user->all();
    }
    
    public function getUserById(int $id): ?array {
        return $this->user->find($id);
    }
    
    public function createUser(array $data): ?string {
        // Validación básica
        if (empty($data['name']) || empty($data['email'])) {
            throw new \InvalidArgumentException('Name and email are required');
        }
        
        // Validar email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }
        
        return $this->user->create($data);
    }
    
    public function updateUser(int $id, array $data): int {
        return $this->user->update($id, $data);
    }
    
    public function deleteUser(int $id): int {
        return $this->user->delete($id);
    }
    
    public function searchUsers(string $query): array {
        $allUsers = $this->user->all();
        $results = [];
        
        foreach ($allUsers as $user) {
            if (stripos($user['name'], $query) !== false || 
                stripos($user['email'], $query) !== false) {
                $results[] = $user;
            }
        }
        
        return $results;
    }
}