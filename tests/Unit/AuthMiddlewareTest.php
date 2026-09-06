<?php

namespace Tests\Unit;

use Apps\ApolloAuth\Middleware\AuthMiddleware;
use Apps\ApolloAuth\Models\User;
use Apps\ApolloAuth\Services\AuthService;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Mockery;
use PHPUnit\Framework\TestCase;

class AuthMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function requestWithToken(?string $token): Request
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/x',
            'HTTP_HOST' => 'localhost',
        ];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = $token;
        }

        return new Request([], [], [], [], [], $server, '');
    }

    public function test_missing_token_returns_401(): void
    {
        $authService = Mockery::mock(AuthService::class);
        $middleware = new AuthMiddleware($authService);

        $response = $middleware->handle(
            $this->requestWithToken(null),
            fn($req) => Response::json(['ok' => true])
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized', json_decode((string) $response->getContent(), true)['error']);
    }

    public function test_invalid_token_returns_401(): void
    {
        $authService = Mockery::mock(AuthService::class);
        $authService->shouldReceive('authenticateFromToken')->once()->with('bad')->andReturnNull();
        $middleware = new AuthMiddleware($authService);

        $response = $middleware->handle(
            $this->requestWithToken('Bearer bad'),
            fn($req) => Response::json(['ok' => true])
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_valid_token_passes_user_to_request(): void
    {
        $user = new User();
        $user->attributes = ['id' => 3, 'username' => 'ana'];

        $authService = Mockery::mock(AuthService::class);
        $authService->shouldReceive('authenticateFromToken')->once()->with('good-token')->andReturn($user);
        $middleware = new AuthMiddleware($authService);

        $captured = null;
        $next = function ($request) use (&$captured) {
            $captured = $request;
            return Response::json(['ok' => true]);
        };

        $response = $middleware->handle($this->requestWithToken('Bearer good-token'), $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($captured, 'El siguiente middleware debe ejecutarse');
        $this->assertSame($user, $captured->user());
        $this->assertSame($user, $captured->attributes['user'] ?? null, 'attributes["user"] debe poblarse también');
    }
}