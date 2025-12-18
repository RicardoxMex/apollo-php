<?php

namespace Apps\ApolloAuth\Models;

use Apollo\Core\Database\Model;

class Role extends Model
{
    protected $table = 'roles';
    
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'permissions',
        'is_system'
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean'
    ];

    /**
     * Users with this role
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }

    /**
     * Count users with this role
     */
    public function usersCount(): int
    {
        $query = new \Apollo\Core\Database\QueryBuilder(
            self::getConnection(),
            'user_roles'
        );
        
        $result = $query->where('role_id', $this->id)->count();
        return $result ?? 0;
    }

    /**
     * Check if role has permission
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->permissions) {
            return false;
        }

        return in_array($permission, $this->permissions) || 
               in_array('*', $this->permissions);
    }

    /**
     * Add permission to role
     */
    public function addPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];
        
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            return $this->update(['permissions' => $permissions]);
        }

        return true;
    }

    /**
     * Remove permission from role
     */
    public function removePermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];
        $permissions = array_filter($permissions, fn($p) => $p !== $permission);
        
        return $this->update(['permissions' => array_values($permissions)]);
    }

    /**
     * Scope for system roles
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope for custom roles
     */
    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }
}