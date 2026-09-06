<?php

namespace Apollo\Core\Auth\Middleware;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;

/**
 * PermissionMiddleware — gate de permisos del core.
 *
 * Los permisos requeridos se inyectan por constructor (igual que RoleMiddleware),
 * p. ej.:
 *   $this->container->bind('permission.moderate', fn($app) => new PermissionMiddleware(['users.view', 'content.moderate']));
 */
class PermissionMiddleware
{
    private array $requiredPermissions;

    public function __construct(array $requiredPermissions = [])
    {
        $this->requiredPermissions = $requiredPermissions;
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

        if (empty($this->requiredPermissions)) {
            return $next($request);
        }

        if (!$user->hasAnyPermission($this->requiredPermissions)) {
            return Response::json([
                'error' => 'Forbidden',
                'message' => 'Insufficient permissions',
                'required_permissions' => $this->requiredPermissions
            ], 403);
        }

        return $next($request);
    }
}