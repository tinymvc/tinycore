<?php
namespace Spark\Routing;

use Spark\Container;
use Spark\Contracts\Support\Arrayable;
use Spark\Http\Middleware;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Routing\Contracts\RouterContract;
use Spark\Routing\Exceptions\InvalidNamedRouteException;
use Spark\Routing\Exceptions\RouteNotFoundException;
use Spark\Support\Traits\Macroable;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function sprintf;

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
     * @var array $groupStack
     * 
     * A stack to manage nested route groups and their attributes.
     * This allows for proper inheritance and application of group attributes
     * to routes defined within nested groups.
     */
    private array $groupStack = [];

    /**
     * @var array|callable|null $fallback
     * 
     * The fallback route handler for when no routes match.
     * If set, this will be called instead of throwing RouteNotFoundException.
     */
    private $fallback = null;

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
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function get(string $path, callable|string|array $callback): Route
    {
        return new Route($path, 'GET', $callback);
    }

    /**
     * Add a POST route to the router.
     *
     * @param string $path The path for the POST route.
     * @param callable|string|array $callback The handler or callback for the POST route.
     *
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function post(string $path, callable|string|array $callback): Route
    {
        return new Route($path, 'POST', $callback);
    }

    /**
     * Add a PUT route to the router.
     *
     * @param string $path The path for the PUT route.
     * @param callable|string|array $callback The handler or callback for the PUT route.
     *
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function put(string $path, callable|string|array $callback): Route
    {
        return new Route($path, 'PUT', $callback);
    }

    /**
     * Add a PATCH route to the router.
     *
     * @param string $path The path for the PATCH route.
     * @param callable|string|array $callback The handler or callback for the PATCH route.
     *
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function patch(string $path, callable|string|array $callback): Route
    {
        return new Route($path, 'PATCH', $callback);
    }

    /**
     * Add a DELETE route to the router.
     *
     * @param string $path The path for the DELETE route.
     * @param callable|string|array $callback The handler or callback for the DELETE route.
     *
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function delete(string $path, callable|string|array $callback): Route
    {
        return new Route($path, 'DELETE', $callback);
    }

    /**
     * Add an OPTIONS route to the router.
     *
     * @param string $path The path for the OPTIONS route.
     * @param callable|string|array $callback The handler or callback for the OPTIONS route.
     *
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function options(string $path, callable|string|array $callback): Route
    {
        return new Route($path, 'OPTIONS', $callback);
    }

    /**
     * Add a route that matches any HTTP method to the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function any(string $path, callable|string|array $callback): Route
    {
        return new Route($path, '*', $callback);
    }

    /**
     * Add a route that matches multiple HTTP methods to the router.
     *
     * @param array $methods An array of HTTP methods to match.
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function match(array $methods, string $path, callable|string|array $callback): Route
    {
        $methods = array_map('strtoupper', $methods);

        // Validate HTTP methods
        if (is_debug_mode()) {
            $validMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
            $invalidMethods = array_filter($methods, fn($method) => !in_array($method, $validMethods));
            if (!empty($invalidMethods)) {
                trigger_error(
                    sprintf('Invalid HTTP methods "%s" provided to match(). Valid methods: %s', implode(', ', $invalidMethods), implode(', ', $validMethods)),
                    E_USER_WARNING
                );
            }
        }

        return new Route($path, $methods, $callback);
    }

    /**
     * Add a route with a view to the router.
     *
     * @param string $path The path for the route.
     * @param string $template The template to use for the route.
     *
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function view(string $path, string $template): Route
    {
        return new Route($path, template: $template);
    }

    /**
     * Add a route that renders a Fireline template.
     *
     * This method is a convenience method for adding routes that render
     * Fireline templates, allowing for easy integration with the Fireline
     * templating system.
     *
     * @param string $path The path for the route.
     * @param string $template The Fireline template to render.
     *
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function fireline(string $path, string $template): Route
    {
        return new Route($path, callback: fn() => fireline($template));
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
     * @return \Spark\Routing\Route Returns the router instance to allow method chaining.
     */
    public function redirect(string $from, string $to, int $status = 302): Route
    {
        return new Route($from, 'GET', function () use ($to, $status): never {
            header("Location: $to", true, $status);
            exit;
        });
    }

    /**
     * Register a fallback route for when no other routes match.
     *
     * @param callable|string|array $callback The handler or callback for the fallback route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function fallback(callable|string|array $callback): self
    {
        $this->fallback = $callback;
        return $this;
    }

    /**
     * Registers a resource route with the router.
     *
     * This method creates a set of RESTful routes for a given resource, mapping standard
     * actions (index, create, store, show, edit, update, destroy) to corresponding controller methods.
     * You can customize the routes by specifying only certain actions or excluding specific ones.
     *
     * @param string $path The base path for the resource routes.
     * @param string $controller The controller class that handles the resource actions.
     * @param string|null $name Optional name prefix for the resource routes.
     * @param string|array $middleware Optional middleware to apply to all resource routes.
     * @param string|array $withoutMiddleware Optional middleware to exclude from all resource routes.
     * @param array $only Optional array of actions to include in the resource route.
     * @param array $except Optional array of actions to exclude from the resource route.
     *
     * @return \Spark\Routing\RouteResource The registered Resource Route instance.
     */
    public function resource(
        string $path,
        string $controller,
        string|null $name = null,
        string|array $middleware = [],
        string|array $withoutMiddleware = [],
        array $only = [],
        array $except = [],
    ): RouteResource {
        return new RouteResource($path, $controller, $name, $middleware, $withoutMiddleware, $only, $except);
    }

    /**
     * Adds a group of routes to the router with shared attributes.
     *
     * This method supports method chaining only. Call the returned instance's methods
     * before calling the routes() method to finalize the group.
     *
     * @param array|callable|null $attrsOrCallback Array of shared attributes or the callback function.
     * @param callable|null $callback Optional callback when first parameter is attributes array.
     *
     * @return \Spark\Routing\RouteGroup
     */
    public function group(array|callable|null $attrsOrCallback = null, callable|null $callback = null): RouteGroup
    {
        // Determine attributes and callback
        $attributes = [];
        if (is_array($attrsOrCallback)) {
            $attributes = $attrsOrCallback;
        } elseif (is_callable($attrsOrCallback) && $callback === null) {
            $callback = $attrsOrCallback;
        }

        $this->groupStack[] = []; // Initialize a new group context

        return (new RouteGroup(callback: $callback))
            ->withAttributes($attributes);
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
     * @param string|array $withoutMiddleware Middleware to exclude from this route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function add(
        string $path,
        string|array|null $method = null,
        callable|string|array|null $callback = null,
        string|null $template = null,
        string|null $name = null,
        string|array $middleware = [],
        string|array $withoutMiddleware = []
    ): self {
        $path = '/' . trim($path, '/'); // ensure it starts with a slash
        $method ??= 'GET'; // Set the default method to GET if not provided

        // Define the route properties
        $route = [
            'path' => $path,
            'method' => $method,
            'callback' => $callback,
            'template' => $template,
            'middleware' => $middleware,
            'withoutMiddleware' => $withoutMiddleware,
        ];

        // If inside a pending group, add the route to the pending routes array
        if ($this->isInPendingGroup()) {
            $pendingRoutes = &$this->groupStack[array_key_last($this->groupStack)];
            $pendingRoutes[] = array_merge($route, ['name' => $name]);
            return $this;
        }

        // Store the route by name if given, otherwise add to unnamed routes array
        if (!empty($name)) {
            // Check for duplicate route names in production
            if (isset($this->routes[$name]) && is_debug_mode()) {
                trigger_error(
                    sprintf('Route name "%s" is already registered and will be overwritten.', $name),
                    E_USER_WARNING
                );
            }
            $this->routes[$name] = $route;
        } else {
            $this->routes[] = $route;
        }

        return $this;
    }

    /**
     * Check if the router is currently within a pending group context.
     *
     * @return bool True if inside a pending group, false otherwise.
     */
    private function isInPendingGroup(): bool
    {
        return !empty($this->groupStack);
    }

    /**
     * Retrieve and remove the most recent pending routes from the group stack.
     *
     * @return array The array of pending routes.
     */
    public function getPendingRoutes(): array
    {
        return array_pop($this->groupStack) ?? [];
    }

    /**
     * Get the URL path for a named route.
     *
     * @param string $name The name of the route.
     * @param null|string|array|Arrayable $context Optional context parameter for dynamic segments.
     *
     * @return string Returns the route's path.
     *
     * @throws \Spark\Routing\Exceptions\InvalidNamedRouteException if the route does not exist.
     */
    public function route(string $name, null|string|array|Arrayable $context = null): string
    {
        // Retrieve the route path by name or throw an exception
        $route = $this->routes[$name]['path'] ?? null;
        if ($route === null) {
            throw new InvalidNamedRouteException(sprintf('Route (%s) does not exist.', $name));
        }

        // Replace dynamic parameters in route path with context, if provided
        if ($context !== null) {
            // Convert Arrayable context to array
            if ($context instanceof Arrayable) {
                $context = $context->toArray();
            }

            // Function to escape replacement values
            $escape = fn($val): string => preg_replace('/[\$\\\\]/', '\\\\$0', (string) $val);

            if (is_array($context)) {
                foreach ($context as $key => $value) {
                    // Escape the replacement value to prevent regex injection
                    $pattern = sprintf('/\{%s\??\}/', preg_quote((string) $key, '/'));
                    $route = preg_replace($pattern, $escape($value), $route, 1);
                }
            } else {
                // Replace the first non-optional dynamic parameter
                $route = preg_replace('/\{[a-zA-Z0-9_]+\}/', $escape($context), $route, 1);
            }
        }

        // Remove unresolved optional parameters
        $route = preg_replace('/\{[a-zA-Z0-9_]+\?\}/', '', $route);

        // Remove trailing wildcard
        return rtrim($route, '*/');
    }

    /**
     * Check if a named route exists.
     *
     * @param string $name The name of the route.
     *
     * @return bool True if the route exists, false otherwise.
     */
    public function has(string $name): bool
    {
        return isset($this->routes[$name]);
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
     * 
     * @throws \Spark\Routing\Exceptions\RouteNotFoundException If no matching route is found.
     */
    public function dispatch(Container $container, Middleware $middleware, Request $request): Response
    {
        // Iterate through all routes to find a match
        foreach ($this->routes as $route) {
            if ($this->matchRoute($route['method'], $route['path'], $request)) {
                is_debug_mode() && event('app:routeMatched', $route);

                // Add route-specific middleware to the middleware stack
                $middleware->queue($route['middleware']);

                // Execute middleware stack - check for early returns (auth failures, redirects, etc.)
                $middlewareResponse = $middleware->process($request, (array) ($route['withoutMiddleware'] ?? []));
                if ($middlewareResponse !== null) {
                    return $this->parseHttpResponse($middlewareResponse);
                }

                is_debug_mode() && event('app:middlewaresHandled', $middleware->getStack());

                // Prepare the request before invoking the route's callback
                $this->prepareRequestBeforeCallback($container, $request);

                // Handle view rendering or instantiate a class for callback if specified
                if (isset($route['template'])) {
                    $route['callback'] = fn() => view($route['template']);
                }

                $response = $container->call($route['callback'], $request->getRouteParams());

                is_debug_mode() && event('app:routeDispatched');

                // Return the response from the route's callback
                return $this->parseHttpResponse($response);
            }
        }

        // If no route matched and a fallback is defined, use it
        if ($this->fallback !== null) {
            is_debug_mode() && event('app:routeFallback');

            $response = $container->call($this->fallback, $request->getRouteParams());

            is_debug_mode() && event('app:routeDispatched');

            // Return the response from the fallback route's callback
            return $this->parseHttpResponse($response);
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
        if ($routeMethod !== '*' && !(is_array($routeMethod) && in_array('*', $routeMethod))) {
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

            // Set router parameters into request class and return as route matched.
            $request->mergeRouteParams($matches);
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
     * The {id} parameter is specifically matched as numeric only for security.
     *
     * @param string $routePath The route path to escape.
     *
     * @return string The escaped route path.
     */
    private function escapeRoutePath(string $routePath): string
    {
        $pattern = preg_replace(
            ['/\/\{[a-zA-Z0-9_]+\?\}/', '/\{id\}/', '/\{[a-zA-Z0-9_]+\}/'],
            ['(?:/([a-zA-Z0-9_-]+))?', '([0-9]+)', '([a-zA-Z0-9_-]+)'],
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
                    array_map(
                        fn($value) => trim($value, ' /'),
                        $matches
                    )
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

        // If the response is an integer, return a Response with that status code
        // This is useful for returning HTTP status codes directly
        if (is_int($response)) {
            return new Response(statusCode: $response);
        }

        return new Response($response); // Otherwise, convert the response to a string
    }

    /**
     * Prepare the request before invoking the route's callback.
     *
     * This method initializes input errors or other necessary request data
     * before the route's callback is executed.
     *
     * @param Container $container The dependency injection container.
     * @param Request $request The HTTP request instance.
     * 
     * @return void
     */
    private function prepareRequestBeforeCallback(Container $container, Request $request): void
    {
        // Initialize Input Errors on Request Class if session is resolved 
        if ($container->resolved(\Spark\Http\Session::class)) {
            $request->getInputErrors();
        }
    }
}
