<?php

namespace Apollo\Core\Auth\Models;

use Apollo\Core\Database\Model;

/**
 * Permission — permiso individual de la tabla 'permissions'.
 * Los roles se enlazan vía la pivot 'role_permissions'.
 */
class Permission extends Model
{
    protected $table = 'permissions';

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
     * Roles que tienen este permiso (pivot role_permissions)
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions', 'permission_id', 'role_id');
    }
}