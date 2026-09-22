<?php

use Spark\Contracts\Http\MiddlewareInterface;
use Spark\Http\Auth;
use Spark\Http\Request;

/**
 * Simple authentication middleware that checks if the user is authenticated using the specified guards. 
 * If the user is not authenticated, it aborts the request with a 401 status code.
 *
 * @package Spark\Http\Middlewares
 */
abstract class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request The incoming HTTP request.
     * @param \Closure $next The next middleware or request handler.
     * @param mixed ...$guards The guards to check for authentication.
     * @return mixed The response from the next middleware or request handler.
     */
    public function handle(Request $request, \Closure $next, ...$guards): mixed
    {
        $guards = empty($guards) ? ['default'] : $guards;

        foreach ($guards as $guard) {
            $guard = ltrim($guard, '!');

            if (Auth::guard($guard)->check() !== $this->isNot($guard)) {
                return $next($request);
            }
        }

        return $this->failed($request, $guards);
    }

    /**
     * Handle an failed request.
     *
     * @param Request $request The incoming HTTP request.
     * @param array $guards The guards that were checked for authentication.
     * @return mixed The response for the failed request.
     */
    protected function failed(Request $request, array $guards): mixed
    {
        abort(401, 'Unauthenticated.');

        return null; // This line will never be reached, but is added to satisfy the return type.
    }

    /**
     * Determine if the guard is negated (i.e., starts with '!').
     *
     * @param string $guard The guard to check.
     * @return bool True if the guard is negated, false otherwise.
     */
    protected function isNot(string $guard): bool
    {
        return str_starts_with($guard, '!');
    }
}