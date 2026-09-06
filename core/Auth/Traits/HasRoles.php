<?php

namespace Apollo\Core\Auth\Traits;

use Apollo\Core\Auth\Models\Role;

/**
 * HasRoles — módulo de roles y permisos del core.
 *
 * Activable vía config: `config('auth.access.enabled')` (env AUTH_ACCESS_ENABLED).
 * El modelo de rol se resuelve desde `config('auth.access.role_model')` para que
 * cada aplicación pueda aportar el suyo sin que el core dependa de clases de apps.
 */
trait HasRoles
{
    /**
     * Resolve el modelo de rol configurado (default: Apollo\Core\Auth\Models\Role)
     */
    public static function roleModel(): string
    {
        return config('auth.access.role_model', Role::class);
    }

    /**
     * Roles relationship
     */
    public function roles()
    {
        $roleModel = static::roleModel();

        // Obtener roles del usuario desde la tabla pivot
        $query = new \Apollo\Core\Database\QueryBuilder(
            \Apollo\Core\Database\Model::getConnection(),
            'user_roles'
        );

        $userRoles = $query->where('user_id', $this->id)->get();
        $roleIds = array_column($userRoles, 'role_id');

        if (empty($roleIds)) {
            return [];
        }

        // Obtener los roles
        $roles = $roleModel::query()->whereIn('id', $roleIds)->get();

        return $roles;
    }

    /**
     * Check if user has role
     */
    public function hasRole(string $roleName): bool
    {
        foreach ($this->roles() as $role) {
            if ($role->name === $roleName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has any of the given roles
     */
    public function hasAnyRole(array $roleNames): bool
    {
        foreach ($this->roles() as $role) {
            if (in_array($role->name, $roleNames)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has all given roles
     */
    public function hasAllRoles(array $roleNames): bool
    {
        $userRoles = [];

        foreach ($this->roles() as $role) {
            $userRoles[] = $role->name;
        }

        return empty(array_diff($roleNames, $userRoles));
    }

    /**
     * Assign role to user
     */
    public function assignRole(string $roleName, ?int $assignedBy = null): bool
    {
        $roleModel = static::roleModel();
        $role = $roleModel::where('name', $roleName)->first();

        if (!$role) {
            return false;
        }

        if ($this->hasRole($roleName)) {
            return true;
        }

        // Insert into user_roles table
        $query = new \Apollo\Core\Database\QueryBuilder(
            \Apollo\Core\Database\Model::getConnection(),
            'user_roles'
        );

        $query->insert([
            'user_id' => $this->id,
            'role_id' => $role->id,
            'assigned_by' => $assignedBy,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return true;
    }

    /**
     * Remove role from user
     */
    public function removeRole(string $roleName): bool
    {
        $roleModel = static::roleModel();
        $role = $roleModel::where('name', $roleName)->first();

        if (!$role) {
            return false;
        }

        // Delete from user_roles table
        $query = new \Apollo\Core\Database\QueryBuilder(
            \Apollo\Core\Database\Model::getConnection(),
            'user_roles'
        );

        $query->where('user_id', $this->id)
              ->where('role_id', $role->id)
              ->delete();

        return true;
    }

    /**
     * Sync user roles
     */
    public function syncRoles(array $roleNames, ?int $assignedBy = null): bool
    {
        // Remove all current roles
        $query = new \Apollo\Core\Database\QueryBuilder(
            \Apollo\Core\Database\Model::getConnection(),
            'user_roles'
        );
        $query->where('user_id', $this->id)->delete();

        // Add new roles
        foreach ($roleNames as $roleName) {
            $this->assignRole($roleName, $assignedBy);
        }

        return true;
    }

    /**
     * Check if user has permission
     */
    public function hasPermission(string $permission): bool
    {
        foreach ($this->roles() as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has any of the given permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all user permissions
     */
    public function getAllPermissions(): array
    {
        $permissions = [];

        foreach ($this->roles() as $role) {
            $permissions = array_merge($permissions, $role->permissionNames());
        }

        return array_unique($permissions);
    }

    /**
     * Check if user is admin (has admin role or * permission)
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin') || $this->hasPermission('*');
    }
}