<?php
// apps/users/Services/UserService.php

namespace Apps\Users\Services;

use Apps\Users\Models\User;

class UserService {
    
    public function getAllUsers(): array {
        return User::all();
    }
    
    public function getUserById(int $id): ?array {
        return User::find($id);
    }
    
    public function createUser(array $data): array {
        // Validación básica
        if (empty($data['name']) || empty($data['email'])) {
            throw new \InvalidArgumentException('Name and email are required');
        }
        
        // Validar email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }
        
        return User::create($data);
    }
    
    public function updateUser(int $id, array $data): ?array {
        return User::update($id, $data);
    }
    
    public function deleteUser(int $id): bool {
        return User::delete($id);
    }
    
    public function searchUsers(string $query): array {
        $allUsers = User::all();
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