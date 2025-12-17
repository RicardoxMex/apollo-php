<?php
// apps/users/Models/User.php

namespace Apps\Users\Models;

class User {
    private static array $users = [
        ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'],
        ['id' => 2, 'name' => 'Jane Smith', 'email' => 'jane@example.com'],
        ['id' => 3, 'name' => 'Bob Johnson', 'email' => 'bob@example.com']
    ];
    
    public static function all(): array {
        return self::$users;
    }
    
    public static function find(int $id): ?array {
        foreach (self::$users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        return null;
    }
    
    public static function create(array $data): array {
        $newId = count(self::$users) + 1;
        $user = array_merge(['id' => $newId], $data);
        self::$users[] = $user;
        return $user;
    }
    
    public static function update(int $id, array $data): ?array {
        foreach (self::$users as &$user) {
            if ($user['id'] === $id) {
                $user = array_merge($user, $data);
                return $user;
            }
        }
        return null;
    }
    
    public static function delete(int $id): bool {
        foreach (self::$users as $key => $user) {
            if ($user['id'] === $id) {
                unset(self::$users[$key]);
                self::$users = array_values(self::$users); // Reindexar
                return true;
            }
        }
        return false;
    }
}