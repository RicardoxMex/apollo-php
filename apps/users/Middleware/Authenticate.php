<?php
// apps/users/Middleware/Authenticate.php

namespace Apps\Users\Middleware;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;

class Authenticate {
    public function handle(Request $request, $next) {
        // Simular autenticación básica
        $token = $request->header('Authorization');
        
        // Verificar si hay token
        if (!$token) {
            return Response::json([
                'error' => 'Unauthorized',
                'message' => 'Authorization header is required',
                'required_format' => 'Authorization: Bearer your-token-here'
            ], 401);
        }
        
        // Verificar formato del token
        if (!str_starts_with($token, 'Bearer ')) {
            return Response::json([
                'error' => 'Unauthorized',
                'message' => 'Invalid authorization format',
                'required_format' => 'Authorization: Bearer your-token-here'
            ], 401);
        }
        
        // Extraer el token
        $actualToken = substr($token, 7); // Remover "Bearer "
        
        // Validar token (en un caso real, verificarías contra base de datos o JWT)
        $validTokens = [
            'test-token-123' => ['user_id' => 1, 'name' => 'John Doe', 'role' => 'admin'],
            'user-token-456' => ['user_id' => 2, 'name' => 'Jane Smith', 'role' => 'user'],
            'demo-token-789' => ['user_id' => 3, 'name' => 'Demo User', 'role' => 'demo']
        ];
        
        if (!isset($validTokens[$actualToken])) {
            return Response::json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or expired token',
                'valid_tokens_for_demo' => array_keys($validTokens)
            ], 401);
        }
        
        // Agregar información del usuario a la request
        $userInfo = $validTokens[$actualToken];
        $request->attributes['user'] = $userInfo;
        $request->attributes['authenticated'] = true;
        
        // Continuar con la siguiente capa
        return $next($request);
    }
}