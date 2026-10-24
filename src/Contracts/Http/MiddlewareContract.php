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
     * @param string|callable|\Psr\Http\Server\MiddlewareInterface $middleware The middleware class or callable.
     * @return self
     */
    public function register(string $alias, string|callable|\Psr\Http\Server\MiddlewareInterface $middleware): self;

    /**
     * Register an alias for a middleware (alias for register).
     *
     * @param string $alias
     * @param string|callable|\Psr\Http\Server\MiddlewareInterface $middleware
     * @return self
     */
    public function alias(string $alias, string|callable|\Psr\Http\Server\MiddlewareInterface $middleware): self;

    /**
     * Register multiple middlewares at once.
     *
     * @param array $middlewares
     * @return self
     */
    public function registerMany(array $middlewares): self;

    /**
     * Determine if a middleware alias exists.
     *
     * @param string $alias
     * @return bool
     */
    public function has(string $alias): bool;

    /**
     * Get a registered middleware by alias.
     *
     * @param string $alias
     * @return string|callable
     */
    public function get(string $alias): string|callable|\Psr\Http\Server\MiddlewareInterface;

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
     * @param array $queue Optional middleware stack to process for this request.
     * @param array $except An array of middleware keys to skip during processing.
     * @param \Closure|null $destination Optional closure to call if all middleware pass through.
     * @return mixed Response from middleware if early return, null otherwise
     */
    public function process(Request $request, array $queue = [], array $except = [], ?\Closure $destination = null);
}
