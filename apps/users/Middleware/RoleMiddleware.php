<?php
// apps/users/Middleware/RoleMiddleware.php

namespace Apps\Users\Middleware;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;

class RoleMiddleware {
    private array $requiredRoles;
    
    public function __construct(array $requiredRoles = []) {
        $this->requiredRoles = $requiredRoles;
    }
    
    public function handle(Request $request, $next) {
        // Verificar si el usuario está autenticado
        if (!$request->attributes['authenticated'] ?? false) {
            return Response::json([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ], 401);
        }
        
        $user = $request->attributes['user'] ?? null;
        
        if (!$user) {
            return Response::json([
                'error' => 'Unauthorized',
                'message' => 'User information not found'
            ], 401);
        }
        
        $userRole = $user['role'] ?? 'guest';
        
        // Si no se especifican roles requeridos, permitir cualquier usuario autenticado
        if (empty($this->requiredRoles)) {
            return $next($request);
        }
        
        // Verificar si el usuario tiene uno de los roles requeridos
        if (!in_array($userRole, $this->requiredRoles)) {
            return Response::json([
                'error' => 'Forbidden',
                'message' => 'Insufficient permissions',
                'required_roles' => $this->requiredRoles,
                'user_role' => $userRole
            ], 403);
        }
        
        return $next($request);
    }
}