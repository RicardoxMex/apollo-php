<?php
// ValidationMiddleware.php

namespace Apps\Products\Middleware;

use Apollo\Core\Http\Request;
use Closure;

class ValidationMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Add your middleware logic here
        // Example: Check authentication, validate input, etc.
        
        // Continue to next middleware or controller
        return $next($request);
    }
}
