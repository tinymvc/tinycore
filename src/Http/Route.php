<?php

namespace Spark\Http;

use Spark\Contracts\Http\RouteContract;
use Spark\Foundation\Application;
use Spark\Routing\Route;
use Spark\Routing\RouteGroup;
use Spark\Routing\Router;
use Spark\Routing\RouteResource;
use Spark\Support\Traits\Macroable;

/**
 * Class Route
 * 
 * This class provides a simple facade for the Router class, allowing for easy 
 * registration and dispatching of routes.
 *
 * @package Spark\Http
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Route implements RouteContract
{
    use Macroable;

    /**
     * Registers a new GET route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function get($path, $callback): Route
    {
        return Application::$app->get(Router::class)->get($path, $callback);
    }

    /**
     * Registers a new POST route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function post($path, $callback): Route
    {
        return Application::$app->get(Router::class)->post($path, $callback);
    }

    /**
     * Registers a new PUT route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function put($path, $callback): Route
    {
        return Application::$app->get(Router::class)->put($path, $callback);
    }

    /**
     * Registers a new PATCH route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function patch($path, $callback): Route
    {
        return Application::$app->get(Router::class)->patch($path, $callback);
    }

    /**
     * Registers a new DELETE route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function delete($path, $callback): Route
    {
        return Application::$app->get(Router::class)->delete($path, $callback);
    }

    /**
     * Registers a new OPTIONS route with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function options($path, $callback): Route
    {
        return Application::$app->get(Router::class)->options($path, $callback);
    }

    /**
     * Registers a new route that matches any HTTP method with the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function any($path, $callback): Route
    {
        return Application::$app->get(Router::class)->any($path, $callback);
    }

    /**
     * Registers a route that renders a view template with the router.
     *
     * @param string $path The path for the route.
     * @param string $template The view template file name.
     *
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function view($path, $template): Route
    {
        return Application::$app->get(Router::class)->view($path, $template);
    }

    /**
     * Registers a route that renders a Fireline template with the router.
     *
     * @param string $path The path for the route.
     * @param string $template The Fireline template file name.
     *
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function fireline($path, $template): Route
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
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function match(array $methods, string $path, callable|string|array $callback): Route
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
     * @return \Spark\Routing\RouteResource The registered Resource Route instance.
     */
    public static function resource(
        string $path,
        string $controller,
        string|null $name = null,
        string|array $middleware = [],
        string|array $withoutMiddleware = [],
        array $only = [],
        array $except = [],
    ): RouteResource {
        return Application::$app->get(Router::class)->resource($path, $controller, $name, $middleware, $withoutMiddleware, $only, $except);
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
     * @return \Spark\Routing\Route The registered Route instance.
     */
    public static function redirect(string $from, string $to, int $status = 302): Route
    {
        return Application::$app->get(Router::class)->redirect($from, $to, $status);
    }

    /**
     * Registers a group of routes with the same attributes.
     *
     * @param array|callable|null $attrsOrCallback The attributes to be applied to each route.
     * @param callable|null $callback The callback function containing the route definitions.
     *
     * @return \Spark\Routing\RouteGroup The registered Route Group instance.
     */
    public static function group($attrsOrCallback = null, $callback = null): RouteGroup
    {
        return Application::$app->get(Router::class)->group($attrsOrCallback, $callback);
    }
}
