<?php

namespace Tests\Unit;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Request;
use Apollo\Core\Router\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private Container $container;
    private Router $router;

    protected function setUp(): void
    {
        $this->container = Container::getInstance();
        $this->router = new Router($this->container);
    }

    private function request(string $method, string $uri): Request
    {
        return new Request([], [], [], [], [], [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'localhost',
        ], '');
    }

    private function dispatch(string $method, string $uri)
    {
        return $this->router->dispatch($this->request($method, $uri));
    }

    private function body($response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    public function test_closure_route_returns_response(): void
    {
        $this->router->get('/ping', fn() => \Apollo\Core\Http\Response::json(['pong' => true]));

        $response = $this->dispatch('GET', '/ping');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['pong' => true], $this->body($response));
    }

    public function test_unknown_route_returns_404(): void
    {
        $this->router->get('/ping', fn() => \Apollo\Core\Http\Response::json(['pong' => true]));

        $response = $this->dispatch('GET', '/nope');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $this->body($response)['error']);
    }

    public function test_parameters_and_where_constraint(): void
    {
        $this->router->get('/users/{id}', fn($id) => \Apollo\Core\Http\Response::json(['id' => $id]))
            ->where(['id' => '\d+']);

        $ok = $this->dispatch('GET', '/users/42');
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('42', $this->body($ok)['id']);

        // /users/abc no cumple \d+ -> 404
        $notFound = $this->dispatch('GET', '/users/abc');
        $this->assertSame(404, $notFound->getStatusCode());
    }

    public function test_group_prefix_and_middleware_are_merged(): void
    {
        $this->router->group(['prefix' => 'api', 'middleware' => ['m1']], function (Router $router) {
            $router->get('/thing', fn() => \Apollo\Core\Http\Response::json(['ok' => true]));
        });

        $routes = $this->router->getRoutes();
        $route = $routes[0];

        $this->assertSame('/api/thing', $route->uri);
        $this->assertContains('m1', $route->middleware);
    }

    public function test_named_route_url_generation(): void
    {
        $this->router->get('/users/{id}', fn() => \Apollo\Core\Http\Response::json([]))->name('users.show');

        // Reconstruir el índice de rutas nombradas (el boot lo hace vía loadAppRoutes)
        $this->router->getRouteCollection()->rebuildNamedRoutes();

        $this->assertSame('/users/7', $this->router->url('users.show', ['id' => 7]));
    }

    public function test_controller_array_action_is_resolved_from_container(): void
    {
        $this->container->bind('Test\\StubController', fn() => new \Tests\Stubs\StubController());
        $this->router->get('/stub', ['Test\\StubController', 'handle']);

        $response = $this->dispatch('GET', '/stub');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['from' => 'stub'], $this->body($response));
    }

    public function test_action_error_does_not_leak_internals_in_production(): void
    {
        // APP_DEBUG=false (definido en el bootstrap del test)
        $this->router->get('/boom', fn() => throw new \RuntimeException('secret internal detail'));

        $response = $this->dispatch('GET', '/boom');

        $this->assertSame(500, $response->getStatusCode());

        $body = $this->body($response);
        $this->assertArrayNotHasKey('file', $body, 'No debe filtrar el archivo interno');
        $this->assertArrayNotHasKey('line', $body, 'No debe filtrar la línea interna');
    }
}