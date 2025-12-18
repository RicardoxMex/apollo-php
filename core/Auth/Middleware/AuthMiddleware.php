<?php

namespace Apollo\Core\Auth\Middleware;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apollo\Core\Auth\AuthService;
use Closure;

class AuthMiddleware
{
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return $this->unauthorizedResponse('Token not provided');
        }

        $user = $this->authService->authenticateFromToken($token);

        if (!$user) {
            return $this->unauthorizedResponse('Invalid or expired token');
        }

        // Add user to request
        $request->setUser($user);

        return $next($request);
    }

    /**
     * Extract token from request
     */
    private function extractToken(Request $request): ?string
    {
        // Check Authorization header
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Check query parameter
        return $request->query('token');
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
}