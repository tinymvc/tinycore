<?php
namespace Spark;

use Spark\Contracts\RouterContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Exceptions\Routing\InvalidNamedRouteException;
use Spark\Exceptions\Routing\RouteNotFoundException;
use Spark\Http\Middleware;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Support\Traits\Macroable;

/**
 * Class Router
 *
 * A basic router for handling HTTP requests, middleware, and dispatching routes to their respective handlers.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Router implements RouterContract
{
    use Macroable;

    /**
     * @var array $groupAttributes
     *
     * Holds the shared attributes for a group of routes. This is used
     * when defining a group of routes with common properties such as
     * middleware, name prefix, or path prefix.
     */
    private array $groupAttributes = [];

    /**
     * Construct a new router.
     *
     * @param array $routes An array of routes that should be added to the router.
     */
    public function __construct(private array $routes = [])
    {
    }

    /**
     * Retrieves the array of routes registered with the router.
     *
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Add a GET route to the router.
     *
     * @param string $path The path for the GET route.
     * @param callable|string|array $callback The handler or callback for the GET route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function get(string $path, callable|string|array $callback): self
    {
        $this->add($path, 'GET', $callback);
        return $this;
    }

    /**
     * Add a POST route to the router.
     *
     * @param string $path The path for the POST route.
     * @param callable|string|array $callback The handler or callback for the POST route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function post(string $path, callable|string|array $callback): self
    {
        $this->add($path, 'POST', $callback);
        return $this;
    }

    /**
     * Add a PUT route to the router.
     *
     * @param string $path The path for the PUT route.
     * @param callable|string|array $callback The handler or callback for the PUT route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function put(string $path, callable|string|array $callback): self
    {
        $this->add($path, 'PUT', $callback);
        return $this;
    }

    /**
     * Add a PATCH route to the router.
     *
     * @param string $path The path for the PATCH route.
     * @param callable|string|array $callback The handler or callback for the PATCH route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function patch(string $path, callable|string|array $callback): self
    {
        $this->add($path, 'PATCH', $callback);
        return $this;
    }

    /**
     * Add a DELETE route to the router.
     *
     * @param string $path The path for the DELETE route.
     * @param callable|string|array $callback The handler or callback for the DELETE route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function delete(string $path, callable|string|array $callback): self
    {
        $this->add($path, 'DELETE', $callback);
        return $this;
    }

    /**
     * Add an OPTIONS route to the router.
     *
     * @param string $path The path for the OPTIONS route.
     * @param callable|string|array $callback The handler or callback for the OPTIONS route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function options(string $path, callable|string|array $callback): self
    {
        $this->add($path, 'OPTIONS', $callback);
        return $this;
    }

    /**
     * Add a route that matches any HTTP method to the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function any(string $path, callable|string|array $callback): self
    {
        $this->add($path, '*', $callback);
        return $this;
    }

    /**
     * Add a route that matches multiple HTTP methods to the router.
     *
     * @param array $methods An array of HTTP methods to match.
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function match(array $methods, string $path, callable|string|array $callback): self
    {
        $this->add($path, $methods, $callback);
        return $this;
    }

    /**
     * Add a route with a view to the router.
     *
     * @param string $path The path for the route.
     * @param string $template The template to use for the route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function view(string $path, string $template): self
    {
        $this->add(path: $path, template: $template);
        return $this;
    }

    /**
     * Assign middleware to the most recently added route.
     *
     * @param string|array $middleware An array of middleware to be associated with the route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function middleware(string|array $middleware): self
    {
        // Merge group middleware with specified route middleware
        $groupedMiddlewares = array_merge(...array_map(fn($attr) => (array) ($attr['middleware'] ?? []), $this->groupAttributes));
        $middleware = array_unique(array_merge((array) $middleware, array_filter($groupedMiddlewares)));

        // Set the middleware for the last added route
        $this->routes[array_key_last($this->routes)]['middleware'] = $middleware;
        return $this;
    }

    /**
     * Redirect to a specified path.
     *
     * This method adds a route that redirects to a specified URL with an optional HTTP status code.
     *
     * @param string $from The path for the redirect route.
     * @param string $to The URL to redirect to.
     * @param int $status The HTTP status code for the redirect (default is 302).
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function redirect(string $from, string $to, int $status = 302): self
    {
        $this->add($from, 'GET', function () use ($to, $status): never {
            header("Location: $to", true, $status);
            exit;
        });
        return $this;
    }

    /**
     * Assign a name to the most recently added route.
     *
     * @param string $name The name to assign to the route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function name(string $name): self
    {
        // Check if there are any group attributes to apply to the route name
        $groupedName = array_map(fn($attr) => $attr['name'] ?? null, $this->groupAttributes);
        $groupedName = implode('', array_filter($groupedName));
        if (!empty($groupedName)) {
            $name ??= '';
            $name = "$groupedName$name";
        }

        // Set the name for the last added route
        $key = array_key_last($this->routes);
        $this->routes[$name] = $this->routes[$key];

        unset($this->routes[$key]);

        return $this;
    }

    /**
     * Add a new route to the router.
     *
     * @param string $path Route path.
     * @param string|array|null $method HTTP method(s) allowed for this route.
     * @param callable|string|array|null $callback The handler or callback for the route.
     * @param string|null $template Optional template for the route.
     * @param string|null $name Optional name for the route.
     * @param string|array $middleware Middleware specific to this route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function add(
        string $path,
        string|array|null $method = null,
        callable|string|array|null $callback = null,
        string|null $template = null,
        string|null $name = null,
        string|array $middleware = []
    ): self {
        // Set the default method to GET if not provided
        $method ??= 'GET';

        // Check if there are any group attributes to apply to the route
        if (!empty($this->groupAttributes)) {
            // Prepend the grouped path to the route path if it exists
            $groupedPath = array_merge(...array_map(fn($attr) => (array) ($attr['path'] ?? []), $this->groupAttributes));
            $groupedPath = implode('', array_filter($groupedPath));
            $path = "$groupedPath$path";

            // Merge grouped methods with specified route methods
            $groupedMethods = array_merge(...array_map(fn($attr) => (array) ($attr['method'] ?? []), $this->groupAttributes));
            $method = array_unique(array_merge((array) $method, array_filter($groupedMethods)));

            // Merge group middleware with specified route middleware
            $groupedMiddlewares = array_merge(...array_map(fn($attr) => (array) ($attr['middleware'] ?? []), $this->groupAttributes));
            $middleware = array_unique(array_merge((array) $middleware, array_filter($groupedMiddlewares)));

            // Append grouped name to the route name if both are set
            $groupedName = array_merge(...array_map(fn($attr) => (array) ($attr['name'] ?? []), $this->groupAttributes));
            $groupedName = implode('', array_filter($groupedName));
            if (!empty($groupedName)) {
                $name ??= '';
                $name = "$groupedName$name";
            }

            // Prepend grouped template path to the route template if both are set
            $groupedTemplate = array_merge(...array_map(fn($attr) => (array) ($attr['template'] ?? []), $this->groupAttributes));
            $groupedTemplate = implode('', array_filter($groupedTemplate));
            if (!empty($groupedTemplate)) {
                $template ??= '';
                $template = "$groupedTemplate$template";
            }

            // If group callback is set and no template is used, apply it to the callback
            $groupedCallback = array_map(fn($attr) => $attr['callback'] ?? null, $this->groupAttributes);
            $groupedCallback = array_filter($groupedCallback);
            if (empty($template) && !empty($groupedCallback)) {
                $groupedCallback = end($groupedCallback); // Get the last callback from the group attributes
                $callback = match (true) {
                    $callback === null => $groupedCallback,
                    is_string($callback) && !is_array($groupedCallback) && !is_callable($callback) => [$groupedCallback, $callback],
                    default => $callback, // original callback for closure or array
                };
            }
        }

        // Define the route properties
        $route = [
            'path' => $path,
            'method' => $method,
            'callback' => $callback,
            'template' => $template,
            'middleware' => $middleware,
        ];

        // Store the route by name if given, otherwise add to unnamed routes array
        if (!empty($name)) {
            $this->routes[$name] = $route;
        } else {
            $this->routes[] = $route;
        }

        return $this;
    }

    /**
     * Registers a resource route with the router.
     *
     * This method creates a set of RESTful routes for a resource, allowing
     * for standard CRUD operations. The routes can be filtered by 'only' or
     * 'except' parameters to include or exclude specific actions.
     *
     * @param string $path The base path for the resource routes.
     * @param string $callback The controller class or callback to handle the resource actions.
     * @param array $only An array of actions to include (e.g., ['index', 'show']).
     * @param array $except An array of actions to exclude (e.g., ['create', 'update']).
     * @param string|null $name Optional name prefix for the resource routes.
     * @param string|array $middleware Optional middleware to apply to the resource routes.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function resource(
        string $path,
        string $callback,
        string|null $name = null,
        string|array $middleware = [],
        array $only = [],
        array $except = [],
    ): self {

        $name ??= trim($path, '/');
        $name = str_replace('/', '.', $name);

        // Define the resource routes based on the provided path and callback
        $routes = [
            'index' => ['method' => 'GET', 'path' => $path, 'callback' => [$callback, 'index'], 'name' => "$name.index"],
            'show' => ['method' => 'GET', 'path' => "$path/{id}", 'callback' => [$callback, 'show'], 'name' => "$name.show"],
            'create' => ['method' => 'GET', 'path' => "$path/create", 'callback' => [$callback, 'create'], 'name' => "$name.create"],
            'store' => ['method' => 'POST', 'path' => $path, 'callback' => [$callback, 'store'], 'name' => "$name.store"],
            'edit' => ['method' => 'GET', 'path' => "$path/{id}/edit", 'callback' => [$callback, 'edit'], 'name' => "$name.edit"],
            'update' => ['method' => ['PUT', 'PATCH'], 'path' => "$path/{id}", 'callback' => [$callback, 'update'], 'name' => "$name.update"],
            'destroy' => ['method' => 'DELETE', 'path' => "$path/{id}", 'callback' => [$callback, 'destroy'], 'name' => "$name.destroy"],
        ];

        // Filter routes based on 'only' and 'except' parameters
        if (!empty($only)) {
            $routes = array_intersect_key($routes, array_flip($only));
        }
        if (!empty($except)) {
            $routes = array_diff_key($routes, array_flip($except));
        }

        // Register the resource routes
        foreach ($routes as $route) {
            $this->add(
                path: $route['path'],
                method: $route['method'],
                callback: $route['callback'],
                name: $route['name'],
                middleware: $middleware
            );
        }

        return $this;
    }

    /**
     * Adds a group of routes to the router with shared attributes.
     *
     * The passed callback is called immediately. Any routes defined within the
     * callback will have the given attributes applied to them.
     *
     * @param array $attributes An array of shared attributes for the group of routes.
     * @param callable $callback The callback that defines the group of routes.
     *
     * @return void
     */
    public function group(array $attributes, callable $callback): void
    {
        // Store the group attributes that will be applied to all routes within the group
        $this->groupAttributes[] = $attributes;

        // Call the callback immediately to define the group of routes
        $callback($this);

        // Remove the last group attributes after the callback has been executed
        array_pop($this->groupAttributes);
    }

    /**
     * Get the URL path for a named route.
     *
     * @param string $name The name of the route.
     * @param string|null|array $context Optional context parameter for dynamic segments.
     *
     * @return string Returns the route's path.
     *
     * @throws InvalidNamedRouteException if the route does not exist.
     */
    public function route(string $name, null|string|array $context = null): string
    {
        // Retrieve the route path by name or throw an exception
        $route = $this->routes[$name]['path'] ?? null;
        if ($route === null) {
            throw new InvalidNamedRouteException(sprintf('Route (%s) does not exist.', $name));
        }

        // Replace dynamic parameters in route path with context, if provided
        if ($context !== null) {
            if (is_array($context)) {
                foreach ($context as $key => $value) {
                    $pattern = sprintf('/\{%s\??\}/', preg_quote($key, '/'));
                    $route = preg_replace($pattern, $value, $route);
                }
            } else {
                // Replace any non-specified dynamic parameters
                $route = preg_replace('/\{[a-zA-Z]+\??\}/', $context, $route);
            }
        }

        // Remove unresolved optional parameters
        $route = preg_replace('/\{[a-zA-Z]+\?\}/', '', $route);

        // Remove trailing wildcard
        return rtrim($route, '*/');
    }

    /**
     * Dispatches the incoming HTTP request to the appropriate route handler.
     *
     * Iterates through the defined routes to find a match for the request path and method.
     * If a route matches, it queues any route-specific middleware and processes the middleware stack.
     * The response is returned if the middleware halts the request. Otherwise, it handles template
     * rendering or resolves the callback for the matched route, returning the callback's response.
     * If no route matches, a 404 'Not Found' response is returned.
     *
     * @param Container $container The dependency injection container.
     * @param Middleware $middleware The middleware stack to be processed.
     * @param Request $request The HTTP request instance.
     *
     * @return Response The HTTP response object.
     */
    public function dispatch(Container $container, Middleware $middleware, Request $request): Response
    {
        // Iterate through all routes to find a match
        foreach ($this->routes as $route) {
            if ($this->matchRoute($route['method'], $route['path'], $request)) {
                // Add route-specific middleware to the middleware stack
                $middleware->queue($route['middleware']);

                // Execute middleware stack and return response if middleware stops request
                $middlewareResponse = $middleware->process($container, $request);
                if ($middlewareResponse) {
                    return $this->parseHttpResponse($middlewareResponse);
                }

                // Handle view rendering or instantiate a class for callback if specified
                if (isset($route['template'])) {
                    $route['callback'] = fn() => view($route['template']);
                }

                // Call the matched route's callback
                return $this->parseHttpResponse(
                    $container->call($route['callback'], $request->getRouteParams())
                );
            }
        }

        // Throw an exception for no matching route
        throw new RouteNotFoundException('No matching route found.');
    }

    /**
     * Attempts to match the request path with the given route path and method.
     *
     * @param string|array $routeMethod The HTTP method(s) allowed for this route.
     * @param string $routePath The route path to match against the request path.
     * @param Request $request The request object.
     *
     * @return bool True if the route matches the request path and method, false otherwise.
     */
    private function matchRoute($routeMethod, $routePath, Request $request): bool
    {
        if ($routeMethod !== '*') {
            // Convert route method to uppercase
            $routeMethod = array_map('strtoupper', (array) $routeMethod);

            // Check if the request method is allowed for this route
            if (!in_array($request->getMethod(), $routeMethod)) {
                return false;
            }
        }

        // Escape special characters in the route path
        $pattern = $this->escapeRoutePath($routePath);

        // Attempt to match the request path with the route pattern
        if (preg_match("/^$pattern\$/", $request->getPath(), $matches)) {
            array_shift($matches);

            // Map matched segments to parameter names
            $matches = $this->getRouteParameters($routePath, $matches);

            // Set router parameters into reqouest class and return as route matched.
            $request->setRouteParams($matches);
            return true;
        }

        // returns as route not matched.
        return false;
    }

    /**
     * Escapes special characters in the route path for use in regular expressions.
     *
     * Replaces '/' with '\/' and '*' with '(.*)'. Also replaces optional dynamic
     * parameters (/{param?}/) with optional groups (?:/([a-zA-Z0-9_-]+))? and
     * required dynamic parameters (/{param}/) with required groups ([a-zA-Z0-9_-]+).
     *
     * @param string $routePath The route path to escape.
     *
     * @return string The escaped route path.
     */
    private function escapeRoutePath(string $routePath): string
    {
        $pattern = preg_replace(
            ['/\/\{[a-zA-Z]+\?\}/', '/\{[a-zA-Z]+\}/'],
            ['(?:/([a-zA-Z0-9_-]+))?', '([a-zA-Z0-9_-]+)'],
            $routePath
        );

        return str_replace(['/', '*'], ['\/', '(.*)'], $pattern);
    }

    /**
     * Maps matched segments to parameter names in the route path.
     *
     * If the number of parameter names matches the number of segments, map the
     * segments to the parameter names, otherwise return the original matches.
     *
     * @param string $routePath The route path to map.
     * @param array $matches The matched segments.
     *
     * @return array The mapped parameters.
     */
    private function getRouteParameters(string $routePath, array $matches): array
    {
        // Map matched segments to parameter names in the route path
        if (preg_match_all('/\{([^\}]+)\}/', $routePath, $names)) {
            if (count($names[1]) === count($matches)) {
                // If the number of parameter names matches the number of segments,
                // map the segments to the parameter names
                $matches = array_combine(
                    array_map(
                        fn($name) => str_replace('?', '', $name),
                        $names[1]
                    ),
                    $matches
                );
            }
        }

        // Return the matched parameters
        return $matches;
    }

    /**
     * Parses the HTTP response and returns a Response object.
     *
     * This method checks the type of the response and converts it to a Response object.
     * It handles strings, arrays, and Arrayable objects, converting them to JSON responses
     * when necessary. If the response is already a Response object, it returns it directly.
     *
     * @param mixed $response The response to parse.
     *
     * @return Response The parsed response object.
     */
    private function parseHttpResponse(mixed $response): Response
    {
        // If the response is already a Response object, return it
        if ($response instanceof Response) {
            return $response;
        }

        // If the response is a string, create a new Response object with it
        if (is_string($response) || is_numeric($response) || is_bool($response)) {
            return new Response($response);
        }

        // If the response is an array, convert it to JSON and return as a Response
        if (is_array($response)) {
            return new Response(json_encode(toPureArray($response)), 200, ['Content-Type' => 'application/json']);
        }

        // If the response implements Arrayable, convert it to an array and return as JSON
        if ($response instanceof Arrayable) {
            return new Response(json_encode(toPureArray($response->toArray())), 200, ['Content-Type' => 'application/json']);
        }

        // Otherwise, return an empty response
        return new Response();
    }
}
