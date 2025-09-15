<?php

namespace Spark\Routing;

use Spark\Foundation\Application;
use Spark\Routing\Contracts\RouteGroupContract;
use Spark\Routing\Exceptions\InvalidGroupAttributeException;

/**
 * Class RouteGroup
 * 
 * Handles grouping of routes with shared attributes like middleware, prefix, etc.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class RouteGroup implements RouteGroupContract
{
    /**
     * Initialize a new RouteGroup instance.
     *
     * @param array $group The group attributes.
     * @param callable|string|array|null $callback The callback to define routes within the group.
     */
    public function __construct(private array $group = [], private $callback = null)
    {
    }

    /**
     * Assign middleware to the most recently added route or pending group.
     *
     * @param string|array $middleware An array of middleware to be associated with the route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function middleware(string|array $middleware): self
    {
        $this->mergeGroupAttributes('middleware', $middleware);
        return $this;
    }

    /**
     * Assign withoutMiddleware to the most recently added route or pending group.
     *
     * @param string|array $middleware An array of middleware to be excluded from the route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function withoutMiddleware(string|array $middleware): self
    {
        $this->mergeGroupAttributes('withoutMiddleware', $middleware);
        return $this;
    }

    /**
     * Set path prefix for pending group.
     *
     * @param string $name The path prefix to set.
     * @return self Returns the router instance to allow method chaining.
     */
    public function prefix(string $name): self
    {
        $this->concatGroupAttributes('path', str_replace('.', '/', $name), '/');
        $this->concatGroupAttributes('name', str_replace('/', '.', $name), '.');
        return $this;
    }

    /**
     * Alias for prefix method to set path prefix for pending group.
     *
     * @param string $path The path prefix to set.
     * @return self Returns the router instance to allow method chaining.
     */
    public function path(string $path): self
    {
        $this->concatGroupAttributes('path', $path, '/');
        return $this;
    }

    /**
     * Set callback for pending group.
     *
     * @param callable|string|array $callback The callback to set.
     * @return self Returns the router instance to allow method chaining.
     */
    public function callback(callable|string|array $callback): self
    {
        $this->setGroupAttribute('callback', $callback);
        return $this;
    }

    /**
     * Set controller for pending group.
     *
     * @param string $controller The controller to set.
     * @return self Returns the router instance to allow method chaining.
     */
    public function controller(string $controller): self
    {
        return $this->callback($controller);
    }

    /**
     * Set template for pending group.
     *
     * @param string $template The template to set.
     * @return self Returns the router instance to allow method chaining.
     */
    public function template(string $template): self
    {
        $this->concatGroupAttributes('template', $template, '.');
        return $this;
    }

    /**
     * Set HTTP method for pending group.
     *
     * @param string|array $method The HTTP method(s) to set.
     * @return self Returns the router instance to allow method chaining.
     */
    public function method(string|array $method): self
    {
        $this->mergeGroupAttributes('method', $method);
        return $this;
    }

    /**
     * Assign a name to the most recently added route or pending group.
     *
     * @param string $name The name to assign to the route.
     *
     * @return self Returns the router instance to allow method chaining.
     */
    public function name(string $name): self
    {
        $this->concatGroupAttributes('name', $name, '.');
        return $this;
    }

    /**
     * Merge attributes into the current group on the stack.
     *
     * @param string $name The attribute name.
     * @param string|array $values The attribute value(s) to merge.
     *
     * @return void
     */
    private function mergeGroupAttributes(string $name, string|array $values): void
    {
        $this->group['attributes'][$name] = array_unique(
            array_merge((array) ($this->group['attributes'][$name] ?? []), (array) $values)
        );
    }

    /**
     * Concatenate a string attribute in the current group on the stack.
     *
     * @param string $name The attribute name.
     * @param string $value The attribute value to concatenate.
     * @param string $delimiter The delimiter to use for concatenation (default is '/').
     *
     * @return void
     */
    private function concatGroupAttributes(string $name, string $value, string $delimiter): void
    {
        $currentString = $this->group['attributes'][$name] ?? '';
        $this->group['attributes'][$name] = $this->cleanForConcat($currentString, $value, $delimiter);
    }

    /**
     * Set an attribute in the current group on the stack.
     *
     * @param string $name The attribute name.
     * @param mixed $value The attribute value to set.
     *
     * @return void
     */
    private function setGroupAttribute(string $name, mixed $value): void
    {
        $this->group['attributes'][$name] = $value;
    }

    /**
     * Cleanly concatenate two strings with a delimiter, ensuring no duplicate delimiters.
     *
     * @param string $first The existing string.
     * @param string $second The new string to append.
     * @param string $delimiter The delimiter to use for concatenation.
     * @return string The concatenated string.
     */
    private function cleanForConcat(string $first, string $second, string $delimiter): string
    {
        return trim($first . $delimiter . ltrim($second, $delimiter), $delimiter);
    }

    /**
     * Define routes within the group using a callback.
     *
     * @param callable $callback The callback to define routes.
     * @return self Returns the router instance to allow method chaining.
     */
    public function routes(callable $callback): self
    {
        $this->callback = $callback;
        return $this;
    }

    /**
     * Set multiple attributes for the group.
     *
     * @param array $attributes An associative array of attributes to set.
     * @return self Returns the router instance to allow method chaining.
     *
     * @throws \Spark\Routing\Exceptions\InvalidGroupAttributeException If an invalid attribute name is provided.
     */
    public function withAttributes(array $attributes): self
    {
        foreach ($attributes as $name => $value) {
            if (in_array($name, ['prefix', 'path'])) {
                $this->prefix($value);
            } elseif ($name === 'name') {
                $this->name($value);
            } elseif (in_array($name, ['template', 'view'])) {
                $this->template($value);
            } elseif (in_array($name, ['controller', 'callback'])) {
                $this->callback($value);
            } elseif ($name === 'method') {
                $this->method($value);
            } elseif ($name === 'middleware') {
                $this->middleware($value);
            } elseif ($name === 'withoutMiddleware') {
                $this->withoutMiddleware($value);
            } else {
                throw new InvalidGroupAttributeException("Invalid group attribute: $name");
            }
        }

        return $this;
    }

    /**
     * When the RouteGroup instance is destroyed, register the routes defined within the group.
     */
    public function __destruct()
    {
        if ($this->callback) {
            $router = Application::$app->get(Router::class);

            call_user_func($this->callback, $router);

            $attributes = $this->group['attributes'] ?? [];

            foreach ($router->getPendingRoutes() as $routeData) {
                $path = $routeData['path'];
                $method = $routeData['method'];
                $callback = $routeData['callback'];
                $template = $routeData['template'];
                $name = $routeData['name'];
                $middleware = $routeData['middleware'];
                $withoutMiddleware = $routeData['withoutMiddleware'];

                // Apply pending group attributes
                if (!empty($attributes)) {
                    // Apply path prefix
                    if (isset($attributes['path'])) {
                        $path ??= '';
                        $path = $this->cleanForConcat($attributes['path'], $path, '/');
                    }

                    // Apply method
                    if (isset($attributes['method'])) {
                        $method = array_unique(array_merge((array) $method, (array) $attributes['method']));
                    }

                    // Apply middleware
                    if (isset($attributes['middleware'])) {
                        $middleware = array_unique(array_merge((array) $middleware, (array) $attributes['middleware']));
                    }

                    // Apply withoutMiddleware
                    if (isset($attributes['withoutMiddleware'])) {
                        $withoutMiddleware = array_unique(array_merge((array) $withoutMiddleware, (array) $attributes['withoutMiddleware']));
                    }

                    // Apply name prefix
                    if (isset($attributes['name'])) {
                        $name ??= '';
                        $name = $this->cleanForConcat($attributes['name'], $name, '.');
                    }

                    // Apply template
                    if (isset($attributes['template'])) {
                        $template ??= '';
                        $template = $this->cleanForConcat($attributes['template'], $template, '.');
                    }

                    // Apply controller
                    if (isset($attributes['callback']) && empty($template)) {
                        $callback = match (true) {
                            $callback === null => $attributes['callback'],
                            is_string($callback) && !is_array($attributes['callback']) && !is_callable($callback) => [$attributes['callback'], $callback],
                            default => $callback,
                        };
                    }
                }

                $router->add(
                    path: $path,
                    method: $method,
                    callback: $callback,
                    template: $template,
                    name: $name,
                    middleware: $middleware,
                    withoutMiddleware: $withoutMiddleware,
                );
            }
        }
    }
}
