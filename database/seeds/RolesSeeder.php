<?php

use Apps\ApolloAuth\Models\Role;
use Apps\ApolloAuth\Models\User;

class RolesSeeder
{
    public function run()
    {
        // Crear roles del sistema
        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Full system access',
                'permissions' => ['*'],
                'is_system' => true
            ],
            [
                'name' => 'moderator',
                'display_name' => 'Moderator',
                'description' => 'Moderate content and users',
                'permissions' => [
                    'users.view',
                    'users.edit',
                    'content.moderate',
                    'reports.view'
                ],
                'is_system' => true
            ],
            [
                'name' => 'user',
                'display_name' => 'User',
                'description' => 'Regular user access',
                'permissions' => [
                    'profile.view',
                    'profile.edit',
                    'content.create',
                    'content.edit_own'
                ],
                'is_system' => true
            ],
            [
                'name' => 'guest',
                'display_name' => 'Guest',
                'description' => 'Limited access for guests',
                'permissions' => [
                    'content.view'
                ],
                'is_system' => true
            ]
        ];

        foreach ($roles as $roleData) {
            // Verificar si el rol ya existe
            $existingRole = Role::where('name', $roleData['name'])->first();
            if (!$existingRole) {
                Role::create($roleData);
                echo "✅ Rol '{$roleData['name']}' creado\n";
            } else {
                echo "⚠️  Rol '{$roleData['name']}' ya existe\n";
            }
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
}