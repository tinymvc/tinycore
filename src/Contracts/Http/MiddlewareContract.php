<?php

namespace Spark\Contracts\Http;

use Spark\Http\Request;

/**
 * Interface MiddlewareContract
 * 
 * Defines the contract for a middleware component in the system.
 * Provides methods for registering, queuing, and processing middleware.
 */
interface MiddlewareContract
{
    /**
     * Registers a middleware class.
     * 
     * If $concrete is not given, the $abstract will be used as the class name.
     * 
     * @param string $alias The alias for the middleware.
     * @param string|callable $middleware The middleware class or callable.
     * @return self
     */
    public function register(string $alias, string|callable $middleware): self;

    /**
     * Adds middleware keys to the execution stack.
     * 
     * @param array|string $aliases The middleware aliases to queue for execution.
     * @return self
     */
    public function queue(array|string ...$aliases): self;

    /**
     * Executes the middleware stack and returns the first response
     * from a middleware or null if no response was returned.
     * 
     * @param Request $request The request being processed.
     * @param \Closure $destination The final destination callable if no middleware returns a response.
     * @param array $except An array of middleware keys to skip during processing.
     */
    public function process(Request $request, \Closure $destination, array $except = []);
}