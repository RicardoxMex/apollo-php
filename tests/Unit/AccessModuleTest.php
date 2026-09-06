<?php

namespace Tests\Unit;

use Apollo\Core\Auth\Middleware\PermissionMiddleware;
use Apollo\Core\Auth\Middleware\RoleMiddleware;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Mockery;
use PHPUnit\Framework\TestCase;

class AccessModuleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function requestWithUser($user): Request
    {
        $request = new Request([], [], [], [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/x',
            'HTTP_HOST' => 'localhost',
        ], '');

        if ($user !== null) {
            $request->setUser($user);
        }

        return $request;
    }

    private function next()
    {
        return fn($req) => Response::json(['ok' => true]);
    }

    // ─────────── RoleMiddleware ───────────

    public function test_role_middleware_returns_401_without_user(): void
    {
        $middleware = new RoleMiddleware(['admin']);

        $response = $middleware->handle($this->requestWithUser(null), $this->next());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_role_middleware_passes_when_user_has_role(): void
    {
        $user = Mockery::mock(\Apps\ApolloAuth\Models\User::class);
        $user->shouldReceive('hasAnyRole')->once()->with(['admin'])->andReturnTrue();

        $middleware = new RoleMiddleware(['admin']);
        $response = $middleware->handle($this->requestWithUser($user), $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_role_middleware_forbids_when_user_lacks_role(): void
    {
        $user = Mockery::mock(\Apps\ApolloAuth\Models\User::class);
        $user->shouldReceive('hasAnyRole')->once()->with(['admin'])->andReturnFalse();

        $middleware = new RoleMiddleware(['admin']);
        $response = $middleware->handle($this->requestWithUser($user), $this->next());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden', json_decode((string) $response->getContent(), true)['error']);
    }

    public function test_role_middleware_without_roles_allows_any_authenticated_user(): void
    {
        $user = Mockery::mock(\Apps\ApolloAuth\Models\User::class);
        $user->shouldNotReceive('hasAnyRole');

        $middleware = new RoleMiddleware();
        $response = $middleware->handle($this->requestWithUser($user), $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    // ─────────── PermissionMiddleware ───────────

    public function test_permission_middleware_returns_401_without_user(): void
    {
        $middleware = new PermissionMiddleware(['torneos.delete']);

        $response = $middleware->handle($this->requestWithUser(null), $this->next());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_permission_middleware_passes_when_user_has_permission(): void
    {
        $user = Mockery::mock(\Apps\ApolloAuth\Models\User::class);
        $user->shouldReceive('hasAnyPermission')->once()->with(['torneos.delete'])->andReturnTrue();

        $middleware = new PermissionMiddleware(['torneos.delete']);
        $response = $middleware->handle($this->requestWithUser($user), $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_permission_middleware_forbids_when_user_lacks_permission(): void
    {
        $user = Mockery::mock(\Apps\ApolloAuth\Models\User::class);
        $user->shouldReceive('hasAnyPermission')->once()->with(['torneos.delete'])->andReturnFalse();

        $middleware = new PermissionMiddleware(['torneos.delete']);
        $response = $middleware->handle($this->requestWithUser($user), $this->next());

        $this->assertSame(403, $response->getStatusCode());
    }
}