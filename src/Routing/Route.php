<?php

namespace Spark\Routing;

use Spark\Foundation\Application;
use Spark\Routing\Contracts\RouteContract;

/**
 * Class Route
 *
 * This class represents a single route definition in the application.
 * It allows setting various attributes of the route such as path, method, callback, template, name, and middleware.
 * When the instance is destroyed, it automatically registers the route with the application's router.
 *
 * @package Spark\Routing
 */
class Route implements RouteContract
{
    /**
     * Route constructor.
     *
     * @param string $path The URL path for the route.
     * @param string|array|null $method The HTTP method(s) for the route (e.g., 'GET', 'POST').
     * @param callable|string|array|null $callback The callback function or controller action for the route.
     * @param string|null $template The template to be rendered for the route.
     * @param string|null $name The name of the route.
     * @param string|array $middleware Middleware to be applied to the route.
     * @param string|array $withoutMiddleware Middleware to be excluded from the route.
     */
    public function __construct(
        private string $path,
        private string|array|null $method = null,
        private $callback = null,
        private string|null $template = null,
        private string|null $name = null,
        private string|array $middleware = [],
        private string|array $withoutMiddleware = []
    ) {
    }

    /**
     * Set the URL path for the route.
     *
     * @param string $path The URL path.
     * @return self Returns the current instance for method chaining.
     */
    public function path(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    /**
     * Set the HTTP method(s) for the route.
     *
     * @param string|array|null $method The HTTP method(s).
     * @return self Returns the current instance for method chaining.
     */
    public function method(string|array|null $method): self
    {
        $this->method = $method;
        return $this;
    }

    /**
     * Set the callback for the route.
     *
     * @param callable|string|array|null $callback The callback function or controller action.
     * @return self Returns the current instance for method chaining.
     */
    public function callback(callable|string|array|null $callback): self
    {
        $this->callback = $callback;
        return $this;
    }

    /**
     * Set the template for the route.
     *
     * @param string|null $template The template to be rendered.
     * @return self Returns the current instance for method chaining.
     */
    public function template(string|null $template): self
    {
        $this->template = $template;
        return $this;
    }

    /**
     * Set the name for the route.
     *
     * @param string|null $name The name of the route.
     * @return self Returns the current instance for method chaining.
     */
    public function name(string|null $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set middleware for the route.
     *
     * @param string|array $middleware Middleware to be applied to the route.
     * @return self Returns the current instance for method chaining.
     */
    public function middleware(string|array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    /**
     * Set middleware to be excluded from the route.
     *
     * @param string|array $withoutMiddleware Middleware to be excluded from the route.
     * @return self Returns the current instance for method chaining.
     */
    public function withoutMiddleware(string|array $withoutMiddleware): self
    {
        $this->withoutMiddleware = $withoutMiddleware;
        return $this;
    }

    /**
     * When the Route instance is destroyed, register the route with the application's router.
     */
    public function __destruct()
    {
        Application::$app->get(Router::class)->add(
            path: $this->path,
            method: $this->method,
            callback: $this->callback,
            template: $this->template,
            name: $this->name,
            middleware: $this->middleware,
            withoutMiddleware: $this->withoutMiddleware,
        );
    }
}