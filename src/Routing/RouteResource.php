<?php

namespace Spark\Routing;

use Spark\Foundation\Application;
use Spark\Routing\Contracts\RouteResourceContract;

/**
 * Class RouteResource
 *
 * This class facilitates the creation of RESTful resource routes.
 * It automatically generates standard CRUD routes based on a given path and controller.
 *
 * @package Spark\Routing
 */
class RouteResource implements RouteResourceContract
{
    /**
     * RouteResource constructor.
     *
     * @param string $path The base path for the resource routes.
     * @param string $controller The controller class handling the resource routes.
     * @param string|null $name Optional name prefix for the routes.
     * @param string|array $middleware Middleware to be applied to all routes.
     * @param string|array $withoutMiddleware Middleware to be excluded from all routes.
     * @param array $only Specific actions to include (e.g., ['index', 'show']).
     * @param array $except Specific actions to exclude (e.g., ['destroy']).
     */
    public function __construct(
        private string $path,
        private string $controller,
        private string|null $name = null,
        private string|array $middleware = [],
        private string|array $withoutMiddleware = [],
        private array $only = [],
        private array $except = [],
    ) {
    }

    /**
     * Set the base path for the resource routes.
     *
     * @param string $path The base path.
     * @return self Returns the current instance for method chaining.
     */
    public function path(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    /**
     * Set the controller for the resource routes.
     *
     * @param string $controller The controller class.
     * @return self Returns the current instance for method chaining.
     */
    public function controller(string $controller): self
    {
        $this->controller = $controller;
        return $this;
    }

    /**
     * Set the name prefix for the resource routes.
     *
     * @param string $name The name prefix.
     * @return self Returns the current instance for method chaining.
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set middleware for the resource routes.
     *
     * @param string|array $middleware Middleware to be applied to all routes.
     * @return self Returns the current instance for method chaining.
     */
    public function middleware(string|array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    /**
     * Set middleware to be excluded from the resource routes.
     *
     * @param string|array $withoutMiddleware Middleware to be excluded from all routes.
     * @return self Returns the current instance for method chaining.
     */
    public function withoutMiddleware(string|array $withoutMiddleware): self
    {
        $this->withoutMiddleware = $withoutMiddleware;
        return $this;
    }

    /**
     * Specify actions to include in the resource routes.
     *
     * @param string|array ...$includes Actions to include (e.g., ['index', 'show']).
     * @return self Returns the current instance for method chaining.
     */
    public function only(string|array ...$includes): self
    {
        $this->only = is_array($includes[0]) ? $includes[0] : $includes;
        return $this;
    }

    /**
     * Specify actions to exclude from the resource routes.
     *
     * @param string|array ...$excludes Actions to exclude (e.g., ['destroy']).
     * @return self Returns the current instance for method chaining.
     */
    public function except(string|array ...$excludes): self
    {
        $this->except = is_array($excludes[0]) ? $excludes[0] : $excludes;
        return $this;
    }

    /**
     * When the RouteResource instance is destroyed, register the resource routes.
     */
    public function __destruct()
    {
        $this->name ??= trim($this->path, '/');
        $this->name = str_replace('/', '.', $this->name);

        // Define the resource routes based on the provided path and callback
        $routes = [
            'index' => ['method' => 'GET', 'path' => $this->path, 'callback' => [$this->controller, 'index'], 'name' => "{$this->name}.index"],
            'store' => ['method' => 'POST', 'path' => $this->path, 'callback' => [$this->controller, 'store'], 'name' => "{$this->name}.store"],
            'create' => ['method' => 'GET', 'path' => "{$this->path}/create", 'callback' => [$this->controller, 'create'], 'name' => "{$this->name}.create"],
            'edit' => ['method' => 'GET', 'path' => "{$this->path}/{id}/edit", 'callback' => [$this->controller, 'edit'], 'name' => "{$this->name}.edit"],
            'update' => ['method' => ['PUT', 'PATCH'], 'path' => "{$this->path}/{id}", 'callback' => [$this->controller, 'update'], 'name' => "{$this->name}.update"],
            'destroy' => ['method' => 'DELETE', 'path' => "{$this->path}/{id}", 'callback' => [$this->controller, 'destroy'], 'name' => "{$this->name}.destroy"],
            'show' => ['method' => 'GET', 'path' => "{$this->path}/{id}", 'callback' => [$this->controller, 'show'], 'name' => "{$this->name}.show"],
        ];

        // Filter routes based on 'only' and 'except' parameters
        if (!empty($this->only)) {
            $routes = array_intersect_key($routes, array_flip($this->only));
        }
        if (!empty($this->except)) {
            $routes = array_diff_key($routes, array_flip($this->except));
        }

        // Register each route with the router
        foreach ($routes as $route) {
            // Add middleware and withoutMiddleware to each route
            $route['middleware'] = $this->middleware;
            $route['withoutMiddleware'] = $this->withoutMiddleware;

            // Register the route in the router
            Application::$app->get(Router::class)->add(...$route);
        }
    }
}
