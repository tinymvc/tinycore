<?php

namespace Spark\Http;

use Spark\Contracts\Http\HttpRouteContract;
use Spark\Foundation\Application;
use Spark\Router;
use Spark\Support\Traits\Macroable;

/**
 * Class Route
 * 
 * This class provides a simple facade for the Router class, allowing for easy 
 * registration and dispatching of routes.
 *
 * @method static Router get(string $path, callable|string|array $callback)
 * @method static Router post(string $path, callable|string|array $callback)
 * @method static Router put(string $path, callable|string|array $callback)
 * @method static Router patch(string $path, callable|string|array $callback)
 * @method static Router delete(string $path, callable|string|array $callback)
 * @method static Router options(string $path, callable|string|array $callback)
 * @method static Router any(string $path, callable|string|array $callback)
 * @method static Router redirect(string $from, string $to, int $status = 302)
 * @method static Router group(array $attributes, callable $callback)
 * @method static Router view(string $path, string $template)
 * @method static Router fireline(string $path, string $template)
 * @method static Router resource(string $path, string $callback, string|null $name = null, string|array $middleware = [], array $only = [], array $except = [])
 *
 * @package Spark\Http
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Route implements HttpRouteContract
{
    use Macroable;

    /**
     * Registers a new GET route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function get($path, $callback): Router
    {
        return Application::$app->get(Router::class)->get($path, $callback);
    }

    /**
     * Registers a new POST route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function post($path, $callback): Router
    {
        return Application::$app->get(Router::class)->post($path, $callback);
    }

    /**
     * Registers a new PUT route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function put($path, $callback): Router
    {
        return Application::$app->get(Router::class)->put($path, $callback);
    }

    /**
     * Registers a new PATCH route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function patch($path, $callback): Router
    {
        return Application::$app->get(Router::class)->patch($path, $callback);
    }

    /**
     * Registers a new DELETE route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function delete($path, $callback): Router
    {
        return Application::$app->get(Router::class)->delete($path, $callback);
    }

    /**
     * Registers a new OPTIONS route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function options($path, $callback): Router
    {
        return Application::$app->get(Router::class)->options($path, $callback);
    }

    /**
     * Registers a new route that matches any HTTP method with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function any($path, $callback): Router
    {
        return Application::$app->get(Router::class)->any($path, $callback);
    }

    /**
     * Registers a route that renders a view template with the router.
     *
     * @param string $path The path for the route.
     * @param string $template The view template file name.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function view($path, $template): Router
    {
        return Application::$app->get(Router::class)->view($path, $template);
    }

    /**
     * Registers a route that renders a Fireline template with the router.
     *
     * @param string $path The path for the route.
     * @param string $template The Fireline template file name.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function fireline($path, $template): Router
    {
        return Application::$app->get(Router::class)->fireline($path, $template);
    }

    /**
     * Matches a route with the specified HTTP methods and path.
     *
     * This method allows you to define a route that responds to specific HTTP
     * methods (GET, POST, etc.) and a path, with a callback or controller action.
     *
     * @param array $methods The HTTP methods to match (e.g., ['get', 'post']).
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function match(array $methods, string $path, callable|string|array $callback): Router
    {
        return Application::$app->get(Router::class)->match($methods, $path, $callback);
    }

    /**
     * Registers a resourceful route with the router.
     *
     * This method allows you to define a set of routes for a resource, such as
     * index, create, store, show, edit, update, and destroy actions.
     *
     * @param string $path The base path for the resource.
     * @param string $callback The controller or callback that handles the resource.
     * @param string|null $name Optional name for the resource route.
     * @param string|array $middleware Optional middleware to apply to the resource routes.
     * @param array $only Optional array of actions to include in the resource route.
     * @param array $except Optional array of actions to exclude from the resource route.
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function resource(
        string $path,
        string $callback,
        string|null $name = null,
        string|array $middleware = [],
        array $only = [],
        array $except = [],
    ): Router {
        return Application::$app->get(Router::class)->resource($path, $callback, $name, $middleware, $only, $except);
    }

    /**
     *  Redirects from one route to another.
     *
     *  This method allows you to define a route that redirects from one path to another.
     *
     * @param string $from The path to redirect from.
     * @param string $to The path to redirect to.
     * @param int $status The HTTP status code for the redirect (default is 302).
     *
     * @return \Spark\Router The router instance to allow method chaining.
     */
    public static function redirect(string $from, string $to, int $status = 302): Router
    {
        return Application::$app->get(Router::class)->redirect($from, $to, $status);
    }

    /**
     * Registers a group of routes with the same attributes.
     *
     * @param array $attributes The attributes to be applied to each route.
     * @param callable $callback The callback function containing the route definitions.
     *
     * @return void
     */
    public static function group($attributes, $callback): void
    {
        Application::$app->get(Router::class)->group($attributes, $callback);
    }
}
