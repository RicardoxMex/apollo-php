<?php

namespace ApolloPHP\Http\Middleware;

use ApolloPHP\Http\Request;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;

interface MiddlewareInterface extends PsrMiddlewareInterface
{
    public function handle(Request $request, callable $next);
}