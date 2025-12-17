<?php
// apps/users/Middleware/LoggingMiddleware.php

namespace Apps\Users\Middleware;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;

class LoggingMiddleware {
    public function handle(Request $request, $next) {
        $startTime = microtime(true);
        
        // Log de la request entrante
        error_log(sprintf(
            "📥 [%s] %s %s - IP: %s - User-Agent: %s",
            date('Y-m-d H:i:s'),
            $request->getMethod(),
            $request->getPath(),
            $request->ip(),
            $request->header('User-Agent') ?? 'Unknown'
        ));
        
        // Ejecutar la siguiente capa
        $response = $next($request);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2); // en milisegundos
        
        // Log de la response
        $statusCode = $response instanceof Response ? $response->getStatusCode() : 'Unknown';
        
        error_log(sprintf(
            "📤 [%s] %s %s - Status: %s - Duration: %sms",
            date('Y-m-d H:i:s'),
            $request->getMethod(),
            $request->getPath(),
            $statusCode,
            $duration
        ));
        
        return $response;
    }
}