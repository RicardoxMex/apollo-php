<?php
// apps/users/Middleware/CorsMiddleware.php

namespace Apps\Users\Middleware;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;

class CorsMiddleware {
    private array $allowedOrigins;
    private array $allowedMethods;
    private array $allowedHeaders;
    
    public function __construct(
        array $allowedOrigins = ['*'],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With']
    ) {
        $this->allowedOrigins = $allowedOrigins;
        $this->allowedMethods = $allowedMethods;
        $this->allowedHeaders = $allowedHeaders;
    }
    
    public function handle(Request $request, $next) {
        // Si es una request OPTIONS (preflight), responder inmediatamente
        if ($request->getMethod() === 'OPTIONS') {
            return $this->createCorsResponse();
        }
        
        // Ejecutar la siguiente capa
        $response = $next($request);
        
        // Agregar headers CORS a la respuesta
        return $this->addCorsHeaders($response, $request);
    }
    
    private function createCorsResponse(): Response {
        return Response::json(['message' => 'CORS preflight'])
            ->withHeaders($this->getCorsHeaders());
    }
    
    private function addCorsHeaders($response, Request $request) {
        if ($response instanceof Response) {
            return $response->withHeaders($this->getCorsHeaders($request));
        }
        
        // Si no es una instancia de Response, crear una nueva
        return Response::json($response)->withHeaders($this->getCorsHeaders($request));
    }
    
    private function getCorsHeaders(?Request $request = null): array {
        $origin = $request ? $request->header('Origin') : null;
        
        // Determinar el origen permitido
        $allowedOrigin = '*';
        if ($origin && !in_array('*', $this->allowedOrigins)) {
            $allowedOrigin = in_array($origin, $this->allowedOrigins) ? $origin : '';
        }
        
        return [
            'Access-Control-Allow-Origin' => $allowedOrigin,
            'Access-Control-Allow-Methods' => implode(', ', $this->allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $this->allowedHeaders),
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400' // 24 horas
        ];
    }
}