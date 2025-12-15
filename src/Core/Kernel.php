<?php

namespace ApolloPHP\Core;

use ApolloPHP\Http\Request;
use ApolloPHP\Http\Response;
use ApolloPHP\Http\Middleware\Pipeline;
use ApolloPHP\Exceptions\HttpException;
use Throwable;

class Kernel
{
    protected Container $container;
    protected Router $router;
    protected array $middleware = [];
    protected array $middlewareGroups = [];
    protected array $routeMiddleware = [];
    
    public function __construct(Container $container, Router $router)
    {
        $this->container = $container;
        $this->router = $router;
    }
    
    public function handle(Request $request): Response
    {
        try {
            $response = $this->sendRequestThroughRouter($request);
        } catch (HttpException $e) {
            $response = $this->renderHttpException($e);
        } catch (Throwable $e) {
            $response = $this->renderException($request, $e);
        }
        
        return $response;
    }
    
    protected function sendRequestThroughRouter(Request $request): Response
    {
        $this->container->instance(Request::class, $request);
        
        // Ejecutar middleware global
        $response = (new Pipeline($this->container))
            ->send($request)
            ->through($this->middleware)
            ->then(function ($request) {
                return $this->dispatchToRouter($request);
            });
        
        return $response;
    }
    
    protected function dispatchToRouter(Request $request): Response
    {
        return $this->router->dispatch($request);
    }
    
    public function middleware(string $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }
    
    public function middlewareGroup(string $name, array $middleware): self
    {
        $this->middlewareGroups[$name] = $middleware;
        return $this;
    }
    
    public function routeMiddleware(string $name, string $middleware): self
    {
        $this->routeMiddleware[$name] = $middleware;
        return $this;
    }
    
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
    
    public function getMiddlewareGroups(): array
    {
        return $this->middlewareGroups;
    }
    
    public function getRouteMiddleware(): array
    {
        return $this->routeMiddleware;
    }
    
    protected function renderHttpException(HttpException $e): Response
    {
        return new Response(
            json_encode([
                'error' => $e->getMessage(),
                'code' => $e->getStatusCode(),
            ]),
            $e->getStatusCode(),
            ['Content-Type' => 'application/json']
        );
    }
    
    protected function renderException(Request $request, Throwable $e): Response
    {
        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $message = $this->container->make('app')->isDebugMode() 
            ? $e->getMessage() 
            : 'Internal Server Error';
        
        return new Response(
            json_encode([
                'error' => $message,
                'code' => $status,
            ]),
            $status,
            ['Content-Type' => 'application/json']
        );
    }
}