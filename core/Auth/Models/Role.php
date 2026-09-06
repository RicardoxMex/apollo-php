<?php

namespace Apollo\Core\Auth\Models;

use Apollo\Core\Database\Model;
use Apollo\Core\Database\QueryBuilder;

/**
 * Role — modelo base del módulo de roles y permisos del core.
 *
 * Los permisos viven en la tabla 'permissions' y se enlazan por la pivot
 * 'role_permissions'. Las aplicaciones pueden aportar su propio modelo de rol
 * vía config('auth.access.role_model') y de permiso vía
 * config('auth.access.permission_model').
 */
class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system'
    ];

    protected $casts = [
        'is_system' => 'boolean'
    ];

    /**
     * Permisos de este rol (pivot role_permissions)
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }

    /**
     * Nombres de permisos de este rol
     */
    public function permissionNames(): array
    {
        $names = [];

        foreach ($this->permissions() as $permission) {
            $names[] = $permission->name;
        }

        return $names;
    }

    /**
     * Check if role has permission ('*' = superpermiso)
     */
    public function hasPermission(string $permission): bool
    {
        $names = $this->permissionNames();

        return in_array($permission, $names) || in_array('*', $names);
    }

    /**
     * Add permission to role (crea el permiso si no existe)
     */
    public function addPermission(string $permission): bool
    {
        $permissionModel = static::permissionModel();
        $perm = $permissionModel::where('name', $permission)->first();

        if (!$perm) {
            $perm = $permissionModel::create(['name' => $permission, 'display_name' => $permission]);
        }

        if (!$perm) {
            return false;
        }

        $query = new QueryBuilder(self::getConnection(), 'role_permissions');
        $exists = $query->where('role_id', $this->id)->where('permission_id', $perm->id)->first();

        if ($exists) {
            return true;
        }

        // first()/get() resetean el builder: usar uno nuevo para el insert
        $insert = new QueryBuilder(self::getConnection(), 'role_permissions');

        return $insert->insert([
            'role_id' => $this->id,
            'permission_id' => $perm->id,
            'created_at' => now(),
            'updated_at' => now()
        ]) !== false;
    }

    /**
     * Remove permission from role
     */
    public function removePermission(string $permission): bool
    {
        $permissionModel = static::permissionModel();
        $perm = $permissionModel::where('name', $permission)->first();

        if (!$perm) {
            return false;
        }

        $query = new QueryBuilder(self::getConnection(), 'role_permissions');

        return $query->where('role_id', $this->id)
            ->where('permission_id', $perm->id)
            ->delete() !== false;
    }

    /**
     * Reemplaza los permisos del rol por la lista dada
     */
    public function syncPermissions(array $permissionNames): bool
    {
        $query = new QueryBuilder(self::getConnection(), 'role_permissions');
        $query->where('role_id', $this->id)->delete();

        foreach ($permissionNames as $permissionName) {
            if (!$this->addPermission($permissionName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve el modelo de permiso configurado
     */
    public static function permissionModel(): string
    {
        return config('auth.access.permission_model', Permission::class);
    }
}