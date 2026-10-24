<?php

namespace Spark\Http\Routing;

use Spark\Contracts\Support\Arrayable;
use Spark\Foundation\Application;
use Spark\Http\Middleware;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Http\Routing\Contracts\RouterContract;
use Spark\Http\Routing\Exceptions\InvalidNamedRouteException;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function count;
use function in_array;
use function is_array;
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
    use Macroable, Conditionable;

    /**
     * @var array $groupStack
     * 
     * A stack to manage nested route groups and their attributes.
     * This allows for proper inheritance and application of group attributes
     * to routes defined within nested groups.
     */
    private array $groupStack = [];

    /**
     * @var null|array $config
     * 
     * Temporary storage for route or group configuration attributes.
     * This is used to apply attributes to routes or groups before they are finalized.
     */
    private null|array $config = null;

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
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
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
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
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
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
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
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
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
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
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
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
     */
    public function options(string $path, callable|string|array $callback): Route
    {
        return new Route($path, 'OPTIONS', $callback);
    }

    /**
     * Add a HEAD route to the router.
     *
     * @param string $path The path for the HEAD route.
     * @param callable|string|array $callback The handler or callback for the HEAD route.
     *
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
     */
    public function head(string $path, callable|string|array $callback): Route
    {
        return new Route($path, 'HEAD', $callback);
    }

    /**
     * Add a route that matches any HTTP method to the router.
     *
     * @param string $path The path for the route.
     * @param callable|string|array $callback The handler or callback for the route.
     *
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
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
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
     */
    public function match(array $methods, string $path, callable|string|array $callback): Route
    {
        $methods = array_map('strtoupper', $methods);

        // Validate HTTP methods
        if (is_debug_mode()) {
            $validMethods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'];
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
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
     */
    public function view(string $path, string $template): Route
    {
        return new Route($path, template: $template);
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
     * @return \Spark\Http\Routing\Route Returns the router instance to allow method chaining.
     */
    public function redirect(string $from, string $to, int $status = 302): Route
    {
        return new Route($from, 'GET', fn(): Response => Response::make()->redirect($to, $status));
    }

    /**
     * Add middleware to the route or group.
     *
     * This method allows you to specify middleware that should be applied to the route or group.
     * Middleware can be defined as a string (class name), an array of strings, or a callable.
     *
     * @param string|array $middleware The middleware(s) to apply.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function middleware(string|array $middleware): self
    {
        $this->config['middleware'] = $middleware;
        return $this;
    }

    /**
     * Exclude middleware from the route or group.
     *
     * This method allows you to specify middleware that should be excluded from the route or group.
     * Middleware can be defined as a string (class name) or an array of strings.
     *
     * @param string|array $middleware The middleware(s) to exclude.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function withoutMiddleware(string|array $middleware): self
    {
        $this->config['withoutMiddleware'] = $middleware;
        return $this;
    }

    /**
     * Set the name for the route or group.
     *
     * This method allows you to specify a name for the route or group, which can be used for generating URLs
     * and referencing the route in other parts of the application.
     *
     * @param string $name The name to assign to the route or group.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function name(string $name): self
    {
        $this->config['name'] = $name;
        return $this;
    }

    /**
     * Alias for the name() method to set the name for the route or group.
     *
     * @param string $name The name to assign to the route or group.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function as(string $name): self
    {
        $this->config['name'] = $name;
        return $this;
    }

    /**
     * Set the prefix for the route or group.
     *
     * This method allows you to specify a URL prefix for the route or group, which will be applied to all routes
     * defined within the group.
     *
     * @param string $name The prefix to assign to the route or group.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function prefix(string $name): self
    {
        $this->config['prefix'] = $name;
        return $this;
    }

    /**
     * Set the path for the route or group.
     *
     * This method allows you to specify a path for the route or group, which will be applied to all routes
     * defined within the group.
     *
     * @param string $name The path to assign to the route or group.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function path(string $name): self
    {
        $this->config['path'] = $name;
        return $this;
    }

    /**
     * Set the HTTP method for the route or group.
     *
     * This method allows you to specify an HTTP method for the route or group, which will be applied to all routes
     * defined within the group.
     *
     * @param string $name The HTTP method to assign to the route or group.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function method(string $name): self
    {
        $this->config['method'] = $name;
        return $this;
    }

    /**
     * Set the callback for the route or group.
     *
     * This method allows you to specify a callback for the route or group, which will be applied to all routes
     * defined within the group.
     *
     * @param callable|string|array $callback The handler or callback for the route or group.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function callback(callable|string|array $callback): self
    {
        $this->config['callback'] = $callback;
        return $this;
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
     * @return \Spark\Http\Routing\RouteResource The registered Resource Route instance.
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
     * @return \Spark\Http\Routing\RouteGroup
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

        if (isset($this->config)) {
            $attributes = [...$this->config, ...$attributes];
            $this->config = null; // Reset config after applying to group
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
        if (isset($this->config)) {
            $attributes = $this->config;
            $this->config = null; // Reset config after applying to route
            $this->group(
                attrsOrCallback: $attributes,
                callback: fn() => $this->add(
                    path: $path,
                    method: $method,
                    callback: $callback,
                    template: $template,
                    name: $name,
                    middleware: $middleware,
                    withoutMiddleware: $withoutMiddleware
                )
            );
            return $this; // Return early since the route will be added within the group context
        }

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
            $pendingRoutes[] = [...$route, 'name' => $name];
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
     * @throws InvalidNamedRouteException if the route does not exist.
     */
    public function route(string $name, null|string|array|Arrayable $context = null): string
    {
        // Retrieve the route path by name or throw an exception
        $route = $this->routes[$name]['path'] ?? null;
        if ($route === null) {
            throw new InvalidNamedRouteException(sprintf('Route (%s) does not exist.', $name));
        }

        $context = $context instanceof Arrayable ? $context->toArray() : $context;
        $route = preg_replace_callback('/(\/?)\{([a-zA-Z0-9_]+)(?::[a-zA-Z0-9_]+)?(\?)?\}/', function ($match) use (&$context, $name) {
            $optional = ($match[3] ?? '') === '?';
            $value = is_array($context) ? ($context[$match[2]] ?? null) : ($optional ? null : $context);
            if (!is_array($context) && !$optional) {
                $context = null;
            }
            if ($value === null) {
                if ($optional) {
                    return '';
                }
                throw new InvalidNamedRouteException(sprintf('Missing parameter (%s) for route (%s).', $match[2], $name));
            }
            return $match[1] . rawurlencode((string) $value);
        }, $route);

        // Remove trailing wildcard
        $route = rtrim($route, '*/');

        return $route !== '' ? $route : '/';
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
     * @param Request $request The HTTP request instance.
     *
     * @return Response The HTTP response object.
     * 
     * @throws RouteNotFoundException If no matching route is found.
     */
    public function dispatch(Request $request): Response
    {
        $matches = [];
        $allowed = [];
        foreach ($this->routes as $route) {
            $pattern = $this->escapeRoutePath($route['path']);
            if (!preg_match("#^$pattern/?$#", $request->getPath(), $captures)) {
                continue;
            }
            array_shift($captures);
            $matches[] = [$route, $this->getRouteParameters($route['path'], $captures)];
            $allowed = [...$allowed, ...array_map('strtoupper', (array) $route['method'])];
        }
        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }
        $allowed = array_values(array_unique($allowed));

        $methods = [strtoupper($request->getMethod())];
        if ($this->isCorsPreflight($request)) {
            $methods[] = strtoupper(trim((string) $request->header('Access-Control-Request-Method', '')));
        }
        if (in_array('HEAD', $methods, true)) {
            $methods[] = 'GET';
        }

        $matched = null;
        foreach (array_unique($methods) as $method) {
            foreach ($matches as [$route, $parameters]) {
                if ($this->routeAllowsMethod($route['method'], $method)) {
                    $matched = $route;
                    $request->mergeRouteParams($parameters);
                    break 2;
                }
            }
        }
        if ($matched !== null) {
            is_debug_mode() && event('app:routeMatched', $matched);
        }

        $middleware = Application::$app->get(Middleware::class);
        $response = $middleware->process(
            request: $request,
            queue: (array) ($matched['middleware'] ?? []),
            except: (array) ($matched['withoutMiddleware'] ?? []),
            destination: function (Request $request) use ($matched, $allowed): Response {
                if ($matched === null) {
                    if ($allowed !== []) {
                        return Response::error(405, 'Method not allowed')->setHeader('Allow', implode(', ', $allowed));
                    }
                    if ($this->fallback !== null) {
                        is_debug_mode() && event('app:routeFallback');
                        $response = Application::$app->call($this->fallback, $request->getRouteParams());
                        is_debug_mode() && event('app:routeDispatched');
                        return Response::from($response);
                    }
                    return Response::error(404, 'Route not found');
                }

                if ($this->isCorsPreflight($request) && !$this->routeAllowsMethod($matched['method'], 'OPTIONS')) {
                    return new Response('', 204);
                }

                $bindingFields = [];
                preg_match_all('/\{([a-zA-Z0-9_]+):([a-zA-Z0-9_]+)\??\}/', $matched['path'], $bindings, PREG_SET_ORDER);
                foreach ($bindings as $binding) {
                    $bindingFields[$binding[1]] = $binding[2];
                }

                $callback = isset($matched['template']) ? fn() => view($matched['template']) : $matched['callback'];
                $response = Application::$app->call($callback, $request->getRouteParams(), $bindingFields);

                is_debug_mode() && event('app:routeDispatched');
                return Response::from($response);
            },
        );

        is_debug_mode() && event('app:middlewaresHandled', $middleware->getStack());
        return Response::from($response);
    }

    /**
     * Determine if the request is a CORS preflight request.
     *
     * @param Request $request
     * @return bool
     */
    private function isCorsPreflight(Request $request): bool
    {
        return strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->header('Origin') !== null
            && $request->header('Access-Control-Request-Method') !== null;
    }

    /**
     * Determine if the route allows the given HTTP method.
     *
     * @param string|array $routeMethod
     * @param string $method
     * @return bool
     */
    private function routeAllowsMethod(array|string $routeMethod, string $method): bool
    {
        if ($routeMethod === '*' || (is_array($routeMethod) && in_array('*', $routeMethod, true))) {
            return true;
        }

        return in_array(strtoupper($method), array_map('strtoupper', (array) $routeMethod), true);
    }

    /**
     * Escapes special characters in the route path for use in regular expressions.
     *
     * Compiles literal segments, wildcard segments, optional dynamic parameters
     * ({param?}), and required dynamic parameters ({param}) into a regex path.
     * Dynamic parameters may specify a model binding field, as in {param:code}.
     *
     * @param string $routePath The route path to escape.
     *
     * @return string The escaped route path.
     */
    private function escapeRoutePath(string $routePath): string
    {
        $routePath = trim($routePath, '/');

        if ($routePath === '') {
            return '\/';
        }

        $segments = array_values(array_filter(explode('/', $routePath), 'strlen'));
        $last = count($segments) - 1;
        $compiled = '';

        foreach ($segments as $i => $segment) {
            // Bare "*": zero or more whole segments
            if ($segment === '*') {
                $compiled .= match (true) {
                    $last === 0 => '\/.*',             // "*" or "/*": everything
                    $i === $last => '(?:\/.*)?',       // "/admin/*": anything below
                    default => '(?:\/[^\/]+)*',        // "/admin/*/edit": any number of segments
                };
                continue;
            }

            // Trailing "*" on a segment: "/admin*" or "/{id}*" = the segment plus anything below it
            $tail = '';
            if (str_ends_with($segment, '*')) {
                $segment = substr($segment, 0, -1);
                $tail = '(?:\/.*)?';
            }

            // {name}, {name?}, {name:type}, {name:type?}
            if (preg_match('/^\{([a-zA-Z0-9_]+)(?::[a-zA-Z0-9_]+)?(\?)?\}$/', $segment, $m)) {
                $compiled .= ($m[2] ?? '') === '?' ? '(?:\/([^\/]+))?' : '\/([^\/]+)';
            } else {
                // Static segment; a "*" inside it matches within one segment ("/files/*.pdf")
                $factory = Application::$app?->get(\Psr\Http\Message\UriFactoryInterface::class) ?? new \Nyholm\Psr7\Factory\Psr17Factory();
                $segment = $factory->createUri()->withPath($segment)->getPath();
                $compiled .= '\/' . str_replace('\*', '[^\/]*', preg_quote($segment, '#'));
            }

            $compiled .= $tail;
        }

        return $compiled;
    }

    /**
     * Maps matched segments to parameter names in the route path.
     *
     * Route parameter names are extracted in declaration order and mapped to each
     * captured route segment by position. Optional parameters unresolved in URL path
     * resolve to null. Routes without placeholders return an empty array.
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
            $parameters = [];

            foreach ($names[1] as $index => $name) {
                $name = explode(':', str_replace('?', '', $name), 2)[0];
                $parameters[$name] = isset($matches[$index]) && $matches[$index] !== '' ? rawurldecode($matches[$index]) : null;
            }

            return $parameters;
        }

        // Return the matched parameters
        return [];
    }

}
