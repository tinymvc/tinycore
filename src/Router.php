<?php

namespace Spark;

use Spark\Contracts\RouterContract;
use Spark\Exceptions\Routing\InvalidNamedRouteException;
use Spark\Exceptions\Routing\RouteNotFoundException;
use Spark\Http\Middleware;
use Spark\Http\Request;
use Spark\Http\Response;

/**
 * Class Router
 * 
 * A basic router for handling HTTP requests, middleware, and dispatching routes to their respective handlers.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Router implements RouterContract
{
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
        $this->routes[array_key_last($this->routes)]['middleware'] = $middleware;
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

            // Prepend the group path to the route path if it exists
            if (isset($this->groupAttributes['path'])) {
                $path = $this->groupAttributes['path'] . $path;
            }

            // Merge group methods with specified route methods
            if (isset($this->groupAttributes['method'])) {
                $method = array_merge((array) $this->groupAttributes['method'], (array) $method);
            }

            // Merge group middleware with specified route middleware
            if (isset($this->groupAttributes['middleware'])) {
                $middleware = array_merge((array) $this->groupAttributes['middleware'], (array) $middleware);
            }

            // Append group name to the route name if both are set
            if (isset($name) && isset($this->groupAttributes['name'])) {
                $name = $this->groupAttributes['name'] . $name;
            }

            // Prepend group template path to the route template if both are set
            if (isset($template) && isset($this->groupAttributes['template'])) {
                $template = $this->groupAttributes['template'] . $template;
            }

            // If group callback is set and no template is used, apply it to the callback
            if (isset($this->groupAttributes['callback']) && $template === null) {
                $callback = match (true) {
                    $callback === null => $this->groupAttributes['callback'],
                    is_string($callback) => [$this->groupAttributes['callback'], $callback],
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
            'middleware' => $middleware
        ];

        // Store the route by name if given, otherwise add to unnamed routes array
        if ($name !== null) {
            $this->routes[$name] = $route;
        } else {
            $this->routes[] = $route;
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
        $this->groupAttributes = $attributes;

        // Call the callback immediately to define the group of routes
        $callback($this);

        // Reset the group attributes after the callback has been executed
        $this->groupAttributes = [];
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
            if ($this->match($route['method'], $route['path'], $request)) {
                // Add route-specific middleware to the middleware stack
                $middleware->queue($route['middleware']);

                // Execute middleware stack and return response if middleware stops request
                $middlewareResponse = $middleware->process($container, $request);
                if ($middlewareResponse) {
                    return $middlewareResponse;
                }

                // Handle view rendering or instantiate a class for callback if specified
                if (isset($route['template'])) {
                    $route['callback'] = fn() => view($route['template']);
                }

                // Call the matched route's callback
                return $container->call($route['callback'], $request->getRouteParams());
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
    private function match($routeMethod, $routePath, Request $request): bool
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
}
