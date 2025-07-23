<?php

namespace Spark\Contracts\Http;

use Spark\Http\Request;

/**
 * Standard Middleware Interface (follows Laravel/Symfony pattern)
 * 
 * Note: Parameters are optional - middleware can use either signature:
 * - handle(Request $request, Closure $next): mixed
 * - handle(Request $request, Closure $next, ...$parameters): mixed
 */
interface MiddlewareWithParametersInterface
{
    /**
     * Handle the request
     * 
     * @param Request $request
     * @param Closure $next
     * @param mixed ...$parameters Optional parameters
     * @return mixed
     */
    public function handle(Request $request, \Closure $next, ...$parameters): mixed;
}