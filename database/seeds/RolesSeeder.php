<?php

use Apollo\Core\Auth\Models\Permission;
use Apollo\Core\Auth\Models\Role;
use Apps\ApolloAuth\Models\User;

class RolesSeeder
{
    /**
     * Los permisos viven en la tabla 'permissions' y se enlazan por la pivot
     * 'role_permissions'. 'admin' tiene '*' (superpermiso).
     */
    public function run()
    {
        $this->ensurePermissions();

        $roles = [
            'admin' => [
                'display' => 'Administrator',
                'description' => 'Full system access',
                'permissions' => ['*'],
            ],
            'moderator' => [
                'display' => 'Moderator',
                'description' => 'Moderate content and users',
                'permissions' => ['users.view', 'users.edit', 'content.moderate', 'reports.view'],
            ],
            'user' => [
                'display' => 'User',
                'description' => 'Regular user access',
                'permissions' => ['profile.view', 'profile.edit', 'content.create', 'content.edit_own'],
            ],
            'guest' => [
                'display' => 'Guest',
                'description' => 'Limited access for guests',
                'permissions' => ['content.view'],
            ],
        ];

        foreach ($roles as $name => $data) {
            $role = Role::where('name', $name)->first();

            if (!$role) {
                $role = Role::create([
                    'name' => $name,
                    'display_name' => $data['display'],
                    'description' => $data['description'],
                    'is_system' => true,
                ]);
                echo "✅ Rol '{$name}' creado\n";
            } else {
                echo "⚠️  Rol '{$name}' ya existe\n";
            }

            $role->syncPermissions($data['permissions']);
        }

        // Crear usuario administrador por defecto
        $existingAdmin = User::where('email', 'admin@apollo.local')->first();

        if (!$existingAdmin) {
            $admin = User::create([
                'username' => 'admin',
                'email' => 'admin@apollo.local',
                'password' => 'admin123',
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'status' => 'active',
                'email_verified_at' => now()
            ]);

            // Asignar rol de admin
            $admin->assignRole('admin');

            echo "✅ Usuario admin creado\n";
            echo "Admin credentials: admin@apollo.local / admin123\n";
        } else {
            echo "⚠️  Usuario admin ya existe\n";
        }

        echo "\n✅ Seeders completados exitosamente!\n";
    }

    private function ensurePermissions(): void
    {
        $catalog = [
            '*', 'users.view', 'users.edit',
            'content.moderate', 'reports.view',
            'profile.view', 'profile.edit',
            'content.create', 'content.edit_own', 'content.view',
        ];

        foreach ($catalog as $name) {
            if (!Permission::where('name', $name)->first()) {
                Permission::create([
                    'name' => $name,
                    'display_name' => $name,
                    'is_system' => true,
                ]);
            }
        }
    }
}