<?php

namespace Spark\Routing\Contracts;

/**
 * Interface for defining a group of routes with shared attributes.
 * 
 * This interface allows setting middleware, prefixes, namespaces,
 * controllers, templates, HTTP methods, and names for a group of routes.
 * 
 * @package Spark\Routing\Contracts
 */
interface RouteGroupContract
{
    /**
     * Define a group of routes with shared attributes.
     *
     * @param string|array $middleware Middleware(s) to apply to the group.
     * @return self
     */
    public function middleware(string|array $middleware): self;

    /**
     * Remove middleware(s) from the group.
     *
     * @param string|array $middleware Middleware(s) to remove from the group.
     * @return self
     */
    public function withoutMiddleware(string|array $middleware): self;

    /**
     * Set a URL prefix for all routes in the group.
     *
     * @param string $path The URL prefix.
     * @return self
     */
    public function prefix(string $path): self;

    /**
     * Set a namespace for all controllers in the group.
     *
     * @param string $namespace The namespace to set.
     * @return self
     */
    public function callback(callable|string|array $callback): self;

    /**
     * Set a controller for all routes in the group.
     *
     * @param string $controller The controller class name.
     * @return self
     */
    public function controller(string $controller): self;

    /**
     * Set a template for all routes in the group.
     *
     * @param string $template The template name.
     * @return self
     */
    public function template(string $template): self;

    /**
     * Set HTTP method(s) for all routes in the group.
     *
     * @param string|array $method HTTP method(s) to set (e.g., 'GET', 'POST').
     * @return self
     */
    public function method(string|array $method): self;

    /**
     * Set a name prefix for all routes in the group.
     *
     * @param string $name The name prefix.
     * @return self
     */
    public function name(string $name): self;

    /**
     * Define routes within the group using a callback.
     *
     * @param callable $callback A callback that defines the routes.
     * @return self
     */
    public function routes(callable $callback): self;

    /**
     * Set multiple attributes for the route group at once.
     *
     * @param array $attributes An associative array of attributes to set.
     * @return self
     */
    public function withAttributes(array $attributes): self;
}