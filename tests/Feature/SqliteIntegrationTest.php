<?php

namespace Tests\Feature;

use Apollo\Core\Auth\Models\Permission;
use Apollo\Core\Auth\Models\Role;
use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Database\Model;
use Apollo\Core\Database\QueryBuilder;
use Apps\ApolloAuth\Models\User;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integración real sobre SQLite :memory: — valida migraciones (DLE con
 * FKs/unique), modelos del módulo de acceso y pivotes, sin necesidad de MySQL.
 * Requiere extension=pdo_sqlite (se omite automáticamente si no está cargada).
 */
class SqliteIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite no disponible: habilita extension=pdo_sqlite en php.ini');
        }

        // Aislar del estado global: bootear config (sin providers para no pisar la config sqlite)
        new \Apollo\Core\Application(dirname(__DIR__, 2));
        \app('config');

        // Helpers de la app (now() para los pivots) sin bootear providers
        require_once dirname(__DIR__, 2) . '/apps/ApolloAuth/helpers.php';

        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // BD fresca (evita tablas de otros tests con la misma config :memory:)
        DatabaseManager::disconnect();

        self::$pdo = DatabaseManager::getConnection();

        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($files);

        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }
    }

    public function test_migrations_create_all_tables(): void
    {
        $tables = self::$pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach (['users', 'roles', 'user_roles', 'permissions', 'role_permissions', 'user_sessions', 'password_resets', 'rate_limits'] as $table) {
            $this->assertContains($table, $tables, "Tabla {$table} debería existir tras las migraciones");
        }
    }

    public function test_role_permission_flow(): void
    {
        $role = Role::create(['name' => 'editor', 'display_name' => 'Editor']);
        $this->assertInstanceOf(Role::class, $role);

        $this->assertTrue($role->addPermission('articles.create'));

        // addPermission crea el permiso si no existe
        $permission = Permission::where('name', 'articles.create')->first();
        $this->assertNotNull($permission);

        $this->assertTrue($role->hasPermission('articles.create'));
        $this->assertSame(['articles.create'], $role->permissionNames());

        // '*' = superpermiso
        $role->addPermission('*');
        $this->assertTrue($role->hasPermission('cualquier.cosa'));

        // Sin el superpermiso, quitar 'articles.create' sí revoca el acceso
        $role->removePermission('*');
        $role->removePermission('articles.create');
        $this->assertFalse($role->hasPermission('articles.create'));
        $this->assertSame([], $role->permissionNames());
    }

    public function test_role_permission_pivot_is_unique(): void
    {
        $role = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer']);
        $role->addPermission('reports.view');

        // addPermission duplicada no inserta (idempotente)
        $this->assertTrue($role->addPermission('reports.view'));

        // Insert directo duplicado: debe violar el unique (role_id, permission_id)
        $this->expectException(\PDOException::class);

        $query = new QueryBuilder(Model::getConnection(), 'role_permissions');
        $query->insert([
            'role_id' => $role->id,
            'permission_id' => Permission::where('name', 'reports.view')->first()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_user_roles_flow_with_core_trait(): void
    {
        $user = User::create([
            'username' => 'sqlite_user',
            'email' => 'sqlite_user@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->assertTrue($user->verifyPassword('secret123'));

        $editor = Role::where('name', 'editor')->first();

        if (!$editor) {
            $editor = Role::create(['name' => 'editor', 'display_name' => 'Editor']);
        }

        // Idempotente: garantiza el permiso independientemente del orden de tests
        $editor->addPermission('articles.create');

        $user->assignRole('editor');

        $this->assertTrue($user->hasRole('editor'));
        $this->assertTrue($user->hasAnyRole(['admin', 'editor']));
        $this->assertContains('articles.create', $user->getAllPermissions());

        $user->removeRole('editor');
        $this->assertFalse($user->hasRole('editor'));
    }

    public function test_role_delete_cascades_to_pivots(): void
    {
        $role = Role::create(['name' => 'temp_role', 'display_name' => 'Temp']);
        $role->addPermission('temp.perm');
        $roleId = $role->id;

        $role->delete();

        $query = new QueryBuilder(Model::getConnection(), 'role_permissions');
        $remaining = $query->where('role_id', $roleId)->count();

        $this->assertSame(0, $remaining, 'Borrar un rol debe limpiar sus pivotes (ON DELETE CASCADE)');
    }
}