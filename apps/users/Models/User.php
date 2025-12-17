<?php
// apps/users/Models/User.php

namespace Apps\Users\Models;

class User {
    private static array $users = [
        ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'],
        ['id' => 2, 'name' => 'Jane Smith', 'email' => 'jane@example.com'],
        ['id' => 3, 'name' => 'Bob Johnson', 'email' => 'bob@example.com'],
        ['id' => 4, 'name' => 'Alice Brown', 'email' => 'alice@example.com'],
        ['id' => 5, 'name' => 'Charlie Wilson', 'email' => 'charlie@example.com'],
        ['id' => 6, 'name' => 'Diana Martinez', 'email' => 'diana@example.com'],
        ['id' => 7, 'name' => 'Edward Davis', 'email' => 'edward@example.com'],
        ['id' => 8, 'name' => 'Fiona Garcia', 'email' => 'fiona@example.com'],
        ['id' => 9, 'name' => 'George Miller', 'email' => 'george@example.com'],
        ['id' => 10, 'name' => 'Helen Anderson', 'email' => 'helen@example.com'],
        ['id' => 11, 'name' => 'Ivan Taylor', 'email' => 'ivan@example.com'],
        ['id' => 12, 'name' => 'Julia Thomas', 'email' => 'julia@example.com'],
        ['id' => 13, 'name' => 'Kevin Jackson', 'email' => 'kevin@example.com']
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