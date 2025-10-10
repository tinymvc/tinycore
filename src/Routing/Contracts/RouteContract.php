<?php

namespace Spark\Routing\Contracts;

/**
 * Interface RouteContract
 *
 * This interface defines the contract for a route in the application.
 * It includes methods for setting various attributes of the route such as path, method, callback, template, name, and middleware.
 *
 * @package Spark\Routing\Contracts
 */
interface RouteContract
{
    /**
     * Set the URL path for the route.
     *
     * @param string $path The URL path.
     * @return self Returns the current instance for method chaining.
     */
    public function path(string $path): self;

    /**
     * Set the HTTP method(s) for the route.
     *
     * @param string|array $method The HTTP method(s).
     * @return self Returns the current instance for method chaining.
     */
    public function method(string|array $method): self;

    /**
     * Set middleware(s) for the route.
     *
     * @param string|array $middleware The middleware(s) to be applied.
     * @return self Returns the current instance for method chaining.
     */
    public function middleware(string|array $middleware): self;

    /**
     * Set middleware(s) to be excluded from the route.
     *
     * @param string|array $withoutMiddleware The middleware(s) to be excluded.
     * @return self Returns the current instance for method chaining.
     */
    public function withoutMiddleware(string|array $withoutMiddleware): self;

    /**
     * Set the callback for the route.
     *
     * @param callable|string|array $callback The callback function or controller action.
     * @return self Returns the current instance for method chaining.
     */
    public function callback(callable|string|array $callback): self;

    /**
     * Set the template for the route.
     *
     * @param string $template The template to be rendered.
     * @return self Returns the current instance for method chaining.
     */
    public function template(string $template): self;

    /**
     * Set the name for the route.
     *
     * @param string $name The name of the route.
     * @return self Returns the current instance for method chaining.
     */
    public function name(string $name): self;
}