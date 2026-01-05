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
     * @param array|string $middleware The middleware aliases to queue for execution.
     * @return self
     */
    public function queue(array|string $middleware): self;

    /**
     * Executes the middleware stack and returns the first response
     * from a middleware if any middleware returns early (auth failure, redirect, etc.),
     * or null if all middleware pass through without returning.
     * 
     * @param Request $request The request being processed.
     * @param array $except An array of middleware keys to skip during processing.
     * @return mixed Response from middleware if early return, null otherwise
     */
    public function process(Request $request, array $except = []);
}