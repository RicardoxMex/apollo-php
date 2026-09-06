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
            self::$app->registerApp($appName);
        }
    }

    public function test_closure_route_with_middleware_returns_200(): void
    {
        [$status, $body] = $this->dispatch('GET', '/api/users/test');

        $this->assertSame(200, $status);
        $this->assertArrayHasKey('message', $body);
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

        $this->assertSame(400, $status);
        $this->assertSame('Validation Error', $body['error']);
    }

    public function test_admin_route_resolves_role_middleware_without_500(): void
    {
        // Sin token: el flujo debe detenerse en auth (401), no en resolución de middleware (500)
        [$status, $body] = $this->dispatch('GET', '/api/auth/admin/users');

        $this->assertSame(401, $status);
        $this->assertSame('Unauthorized', $body['error']);
    }
}