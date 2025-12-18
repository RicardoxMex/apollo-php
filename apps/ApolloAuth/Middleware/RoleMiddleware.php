<?php

namespace Apps\ApolloAuth\Middleware;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Closure;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthorizedResponse('Authentication required');
        }

        if (empty($roles)) {
            return $next($request);
        }

        // Check if user has any of the required roles
        if (!$user->hasAnyRole($roles)) {
            return $this->forbiddenResponse('Insufficient permissions');
        }

        return $next($request);
    }

    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(string $message): Response
    {
        return Response::json([
            'error' => 'Unauthorized',
            'message' => $message
        ], 401);
    }

    /**
     * Return forbidden response
     */
    private function forbiddenResponse(string $message): Response
    {
        return Response::json([
            'error' => 'Forbidden',
            'message' => $message
        ], 403);
    }
}