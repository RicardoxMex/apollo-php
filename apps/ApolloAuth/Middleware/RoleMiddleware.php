<?php

namespace Apps\ApolloAuth\Middleware;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;

class RoleMiddleware
{
    private array $requiredRoles;

    public function __construct(array $requiredRoles = [])
    {
        $this->requiredRoles = $requiredRoles;
    }

    public function handle(Request $request, $next)
    {
        $user = $request->user();

        if (!$user) {
            return Response::json([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ], 401);
        }

        if (empty($this->requiredRoles)) {
            return $next($request);
        }

        if (!$user->hasAnyRole($this->requiredRoles)) {
            return Response::json([
                'error' => 'Forbidden',
                'message' => 'Insufficient permissions',
                'required_roles' => $this->requiredRoles
            ], 403);
        }

        return $next($request);
    }
}