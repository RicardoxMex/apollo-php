<?php
// apps/users/Services/UserService.php

namespace Apps\Users\Services;

use Apollo\Core\Http\Request;
use Apps\Users\Models\User;

class UserService
{
    private User $user;

    public function __construct()
    {
        $this->user = new User();
    }

    public function paginate(): array
    {
        $request = Request::capture();
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('perPage', 10);
        return $this->user->paginate($perPage, $page);
    }

    public function getAllUsers(): array
    {
        return $this->user->all();
    }

    public function getUserById(int $id): ?array
    {
        return $this->user->find($id);
    }

    public function getUserByEmail(string $email): ?array
    {
        return $this->user->query()->where('email', $email)->get();
    }

    public function createUser(array $data): ?string
    {
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

    public function updateUser(int $id, array $data): int
    {
        return $this->user->update($id, $data);
    }

    public function deleteUser(int $id): int
    {
        return $this->user->delete($id);
    }

    public function searchUsers(string $query): array
    {
        return $this->user->search($query);
    }

    public function searchUsersByEmail(string $email): array
    {
        return $this->user->advancedSearch($email, [
            'fields' => ['email'],
            'type' => 'contains'
        ]);
    }

    public function searchUsersByName(string $name, int $limit = 10): array
    {
        return $this->user->advancedSearch($name, [
            'fields' => ['name'],
            'type' => 'starts_with',
            'limit' => $limit,
            'order_by' => 'name',
            'order_direction' => 'ASC'
        ]);
    }

    public function searchUsersExact(string $term): array
    {
        return $this->user->advancedSearch($term, [
            'type' => 'exact'
        ]);
    }

    // Ejemplos de múltiples WHERE
    public function getUserByEmailAndStatus(string $email, string $status): ?array
    {
        return $this->user->findWhere([
            'email' => $email,
            'status' => $status
        ]);
    }

    public function getActiveUsersByRole(string $role): array
    {
        return $this->user->getWhere([
            'role' => $role,
            'status' => 'active'
        ]);
    }

    // Para consultas más complejas con OR
    public function getUsersWithComplexConditions(string $status, int $minAge): array
    {
        return $this->user->query()
            ->where('status', $status)
            ->where('age', '>=', $minAge)
            ->orWhere('role', 'admin')
            ->orderBy('created_at', 'DESC')
            ->get();
    }
}