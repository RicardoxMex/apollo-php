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
            $response = $this->renderHttpException($e, $request);
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
    
    protected function renderHttpException(HttpException $e, ?Request $request = null): Response
    {
        if ($request && $this->shouldReturnJson($request)) {
            return Response::json([
                'error' => $e->getMessage(),
                'code' => $e->getStatusCode(),
            ], $e->getStatusCode());
        }
        
        // Return HTML response for non-JSON requests
        return new Response(
            $this->getErrorHtml($e->getMessage(), $e->getStatusCode()),
            $e->getStatusCode(),
            ['Content-Type' => 'text/html']
        );
    }
    
    protected function renderException(Request $request, Throwable $e): Response
    {
        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $message = $this->container->make('app')->isDebugMode() 
            ? $e->getMessage() 
            : 'Internal Server Error';
        
        if ($this->shouldReturnJson($request)) {
            $data = [
                'error' => $message,
                'code' => $status,
            ];
            
            // Add debug information if in debug mode
            if ($this->container->make('app')->isDebugMode()) {
                $data['debug'] = [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ];
            }
            
            return Response::json($data, $status);
        }
        
        // Return HTML response for non-JSON requests
        return new Response(
            $this->getErrorHtml($message, $status),
            $status,
            ['Content-Type' => 'text/html']
        );
    }
    
    protected function shouldReturnJson(Request $request): bool
    {
        return $request->expectsJson() || 
               $request->isJson() || 
               $request->getHeaderLine('Content-Type') === 'application/json';
    }
    
    protected function getErrorHtml(string $message, int $status): string
    {
        return "<!DOCTYPE html>
<html>
<head>
    <title>Error {$status}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .error { background: #f8f8f8; padding: 20px; border-left: 4px solid #e74c3c; }
        h1 { color: #e74c3c; margin: 0 0 10px 0; }
    </style>
</head>
<body>
    <div class='error'>
        <h1>Error {$status}</h1>
        <p>" . htmlspecialchars($message) . "</p>
    </div>
</body>
</html>";
    }
}