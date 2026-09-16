<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Registrar apps como public/index.php
        $config = self::$app->make('config');

        foreach ($config->get('providers.core', []) as $providerClass) {
            if (class_exists($providerClass)) {
                self::$app->registerServiceProvider(new $providerClass(self::$app));
            }
        }

        foreach ($config->get('providers.app', []) as $providerClass) {
            if (class_exists($providerClass)) {
                self::$app->registerServiceProvider(new $providerClass(self::$app));
            }
        }

        foreach ($config->get('apps.registered', []) as $appName) {
            try {
                self::$app->registerApp($appName);
            } catch (\Throwable $e) {
                // Toleramos apps ausentes (mismo comportamiento que el binario apollo)
                // para que el smoke test no dependa de que cada app esté presente en disco.
            }
        }
    }

    public function test_closure_route_with_middleware_requires_auth(): void
    {
        // /api/users/test ahora está tras auth + role.admin (R-PERIM-01)
        [$status, $body] = $this->dispatch('GET', '/api/users/test');

        $this->assertSame(401, $status);
        $this->assertSame('Unauthorized', $body['error']);
    }

    public function test_products_crud_routes_are_registered_and_reachable(): void
    {
        [$status, $body] = $this->dispatch('GET', '/api/products');

        $this->assertSame(200, $status);
        $this->assertArrayHasKey('message', $body);
    }

    public function test_products_show_is_public(): void
    {
        [$status, $body] = $this->dispatch('GET', '/api/products/5');

        $this->assertSame(200, $status);
        $this->assertSame(5, $body['id'] ?? null);
    }

    public function test_products_writes_require_auth(): void
    {
        // Sin token -> 401 en todas las escrituras
        [$status] = $this->dispatch('POST', '/api/products');
        $this->assertSame(401, $status);

        [$status] = $this->dispatch('PUT', '/api/products/1');
        $this->assertSame(401, $status);

        [$status] = $this->dispatch('DELETE', '/api/products/1');
        $this->assertSame(401, $status);

        // Token inválido -> 401 (el middleware valida JWT antes de llegar al controlador)
        [$status] = $this->dispatch('POST', '/api/products', [
            'Authorization' => 'Bearer invalid-token-abc',
        ]);
        $this->assertSame(401, $status);
    }

    public function test_unknown_route_returns_404_envelope(): void
    {
        [$status, $body] = $this->dispatch('GET', '/api/nope');

        $this->assertSame(404, $status);
        $this->assertSame('Not Found', $body['error']);
    }

    public function test_auth_protected_route_rejects_missing_token(): void
    {
        [$status, $body] = $this->dispatch('GET', '/api/users/profile');

        $this->assertSame(401, $status);
        $this->assertSame('Unauthorized', $body['error']);
    }

    public function test_auth_protected_route_rejects_invalid_token(): void
    {
        [$status, $body] = $this->dispatch('GET', '/api/users/profile', [
            'Authorization' => 'Bearer invalid-token-abc',
        ]);

        $this->assertSame(401, $status);
        $this->assertSame('Unauthorized', $body['error']);
    }

    public function test_login_requires_credentials(): void
    {
        [$status, $body] = $this->dispatch('POST', '/api/auth/login');

        $this->assertSame(422, $status);
        $this->assertSame('Validation Error', $body['error']);
        $this->assertArrayHasKey('errors', $body);
    }

    public function test_admin_route_resolves_role_middleware_without_500(): void
    {
        // Sin token: el flujo debe detenerse en auth (401), no en resolución de middleware (500)
        [$status, $body] = $this->dispatch('GET', '/api/auth/admin/users');

        $this->assertSame(401, $status);
        $this->assertSame('Unauthorized', $body['error']);
    }

    public function test_roles_and_permissions_admin_endpoints_require_auth(): void
    {
        [$status] = $this->dispatch('GET', '/api/auth/admin/roles');
        $this->assertSame(401, $status);

        [$status] = $this->dispatch('POST', '/api/auth/admin/roles');
        $this->assertSame(401, $status);

        [$status] = $this->dispatch('GET', '/api/auth/admin/permissions');
        $this->assertSame(401, $status);

        [$status] = $this->dispatch('POST', '/api/auth/admin/roles/admin/permissions');
        $this->assertSame(401, $status);

        [$status] = $this->dispatch('DELETE', '/api/auth/admin/roles/support/permissions/users.view');
        $this->assertSame(401, $status);
    }

    public function test_core_access_module_registers_role_gates(): void
    {
        // Módulo de acceso del core activo por defecto: los gates resuelven
        $roleAdmin = $this->app()->make('role.admin');

        $this->assertInstanceOf(\Apollo\Core\Auth\Middleware\RoleMiddleware::class, $roleAdmin);
    }
}