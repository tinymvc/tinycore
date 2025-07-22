<?php

namespace Spark\Contracts\Http;

use Closure;
use Spark\Http\Request;

/**
 * Standard Middleware Interface (follows Laravel/Symfony pattern)
 * 
 * Note: Parameters are optional - middleware can use either signature:
 * - handle(Request $request, Closure $next): Response
 * - handle(Request $request, Closure $next, ...$parameters): Response
 */
interface MiddlewareInterface
{
    /**
     * Handle the request
     * 
     * @param Request $request
     * @param Closure $next
     * 
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed;
}