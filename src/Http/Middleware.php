<?php

namespace Spark\Http;

use Spark\Contracts\Http\MiddlewareContract;
use Spark\Contracts\Http\MiddlewareInterface;
use Spark\Exceptions\Http\MiddlewareNotFoundExceptions;
use Spark\Http\Request;
use Spark\Support\Traits\Macroable;
use Closure;
use function in_array;
use function is_array;
use function is_string;

/**
 * Class Middleware
 * 
 * This class provides a standard middleware system for handling HTTP requests.
 * It follows industry standards from Laravel, Symfony, and other major frameworks.
 * The middleware system uses the Pipeline pattern for clean, fast, and predictable
 * middleware execution.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Middleware implements MiddlewareContract
{
    use Macroable;

    /**
     * Middleware constructor.
     * 
     * This initializes the middleware with a map of registered middlewares
     * and an execution stack.
     * 
     * @param array $middlewares Registered middleware map [alias => class]
     * @param array $stack Middleware stack to be processed ['auth', 'role:admin']
     */
    public function __construct(private array $middlewares = [], private array $stack = [])
    {
    }

    /**
     * Register a single middleware
     * 
     * This method allows you to register a middleware with an alias.
     * The alias can be used to refer to the middleware in the stack.
     * 
     * @param string $alias The alias for the middleware.
     * @param string|callable $middleware The middleware class or callable.
     * 
     * @return self
     */
    public function register(string $alias, string|callable $middleware): self
    {
        $this->middlewares[$alias] = $middleware;
        return $this;
    }

    /**
     * Register multiple middlewares at once
     * 
     * This method allows you to register multiple middlewares
     * with their aliases in a single call.
     * 
     * @param array $middlewares An associative array of alias => middleware class/callable.
     * 
     * @return self
     */
    public function registerMany(array $middlewares): self
    {
        $this->middlewares = array_merge($this->middlewares, $middlewares);
        return $this;
    }

    /**
     * Add middlewares to the execution stack
     * 
     * @param array|string ...$middlewares The middleware aliases to queue for execution.
     * @return self
     */
    public function queue(array|string ...$middlewares): self
    {
        $middlewares = is_array($middlewares[0]) ? $middlewares[0] : $middlewares;

        foreach ($middlewares as $key) {
            if (!in_array($key, $this->stack)) {
                $this->stack[] = $key;
            }
        }
        return $this;
    }

    /**
     * Process request through middleware stack
     * 
     * This follows framework standards while optimizing for performance:
     * - Uses industry-standard Pipeline pattern
     * - Handles early returns efficiently
     * - Single-pass execution
     * - Memory efficient
     * 
     * @param Request $request
     * @param array $except Middleware aliases to exclude from execution
     * @return mixed Response from middleware if early return, null otherwise
     */
    public function process(Request $request, array $except = [])
    {
        // Filter out excepted middlewares
        $stack = array_filter($this->stack ?? [], fn($m) => !in_array($m, $except));

        // Fast path: all middlewares excluded = no early return
        if (empty($stack)) {
            return null;
        }

        // Use optimized pipeline processing
        return $this->createPipeline($request, fn() => null, $stack);
    }

    /**
     * Create and execute the middleware pipeline
     * 
     * This is the most efficient approach that follows framework standards
     * 
     * @param Request $request
     * @param Closure $destination
     * @param array $stack
     * @return mixed
     */
    private function createPipeline(Request $request, Closure $destination, array $stack = [])
    {
        // Build the pipeline from the end backwards (most efficient)
        $pipeline = array_reduce(
            array_reverse($stack), // Process in reverse to build pipeline
            fn(Closure $carry, string $middlewareName) => function (Request $request) use ($carry, $middlewareName) {
                // Resolve middleware into callable
                $middleware = $this->resolveMiddleware($middlewareName);
                return $middleware($request, $carry); // Execute middleware and return result
            },
            $destination // Final destination becomes the innermost handler
        );

        // Execute the built pipeline
        return $pipeline($request);
    }

    /**
     * Resolve single middleware into Pipeline-compatible callable
     * 
     * This converts middleware alias or class into a callable
     * that can be used in the Pipeline.
     * 
     * @param string $middleware The middleware alias or class name.
     * @return callable The resolved middleware callable.
     */
    private function resolveMiddleware(string $middleware): callable
    {
        // Parse parameters (e.g., 'auth:admin' -> ['auth', 'admin'])
        [$name, $parameters] = $this->parseMiddleware($middleware);

        // Get middleware class/callable
        $middlewareHandler = $this->getMiddlewareHandler($name);

        // Return Pipeline-compatible callable
        return function (Request $request, Closure $next) use ($middlewareHandler, $parameters) {
            // If middleware is an array (e.g., [class, method]), call it directly
            if (is_array($middlewareHandler)) {
                [$instance, $method] = $middlewareHandler;
                return $instance->$method($request, $next, ...$parameters);
            }

            // Standard middleware pattern: middleware(request, next, ...parameters)
            return $middlewareHandler($request, $next, ...$parameters);
        };
    }

    /**
     * Get middleware handler (class instance or callable)
     * 
     * This resolves the middleware by its name.
     * It can return a callable directly or instantiate a class if needed.
     * 
     * @param string $name The middleware name.
     * @throws MiddlewareNotFoundExceptions If the middleware is not registered.
     * 
     * @return callable The middleware handler.
     */
    private function getMiddlewareHandler(string $name): callable
    {
        // Check if registered
        if (!isset($this->middlewares[$name])) {
            throw new MiddlewareNotFoundExceptions("Middleware '{$name}' not found.");
        }

        $middleware = $this->middlewares[$name];

        // Return callable directly
        if (is_callable($middleware)) {
            return $middleware;
        }

        // Instantiate class and create callable
        if (is_string($middleware) && class_exists($middleware)) {
            $instance = new $middleware();

            if (
                $instance instanceof MiddlewareInterface ||
                method_exists($instance, 'handle')
            ) {
                return [$instance, 'handle'];
            }

            // Return invoke method if available
            if (method_exists($instance, '__invoke')) {
                return $instance;
            }
        }

        throw new MiddlewareNotFoundExceptions("Cannot resolve middleware '{$name}'.");
    }

    /**
     * Parse middleware string into name and parameters
     * 
     * This splits a middleware string like 'auth:admin,editor'
     * into ['auth', ['admin', 'editor']].
     * 
     * @param string $middleware The middleware string to parse.
     * @return array<string, array<string>> Parsed name and parameters.
     */
    private function parseMiddleware(string $middleware): array
    {
        if (!str_contains($middleware, ':')) {
            return [$middleware, []];
        }

        [$name, $parameterString] = explode(':', $middleware, 2);
        $parameters = explode(',', $parameterString);

        return [$name, array_map('trim', $parameters)];
    }

    /**
     * Get registered middlewares
     * 
     * This returns the map of registered middlewares
     * where keys are aliases and values are class names or callables.
     * 
     * @return array<string, string|callable>
     */
    public function getRegistered(): array
    {
        return $this->middlewares;
    }

    /**
     * Get the current middleware stack
     * 
     * This returns the stack of middleware that will be processed.
     * 
     * @return array<string>
     */
    public function getStack(): array
    {
        return $this->stack;
    }
}
