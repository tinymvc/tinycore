<?php

namespace Spark;

use Generator;
use InvalidArgumentException;
use Spark\Contracts\PipeInterface;
use Throwable;
use Closure;

/**
 * Pipeline class for managing a series of processing steps (pipes).
 *
 * This class allows you to create a pipeline of operations that can be executed
 * sequentially, with support for middleware, error handling, and context management.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Pipeline
{
    /** @var array List of pipes to be executed in the pipeline. */
    private array $pipes = [];

    /** @var array List of middleware functions that wrap around the entire pipeline. */
    private array $middleware = [];

    /** @var Closure|null Error handler that will be called if an error occurs during pipeline execution. */
    private ?Closure $errorHandler = null;

    /** @var bool Whether to stop execution on the first error encountered. */
    private bool $stopOnError = true;

    /** @var array Context data that can be passed to pipes and middleware. */
    private array $context = [];

    /** @var bool Whether to enable debug mode for logging pipeline operations. */
    private bool $debug = false;

    /** @var array Debug logs collected during pipeline execution. */
    private array $logs = [];

    /**
     * Constructor to initialize the pipeline with an optional initial payload.
     * 
     * @param mixed $payload The initial payload to be processed by the pipeline.
     */
    public function __construct(
        private mixed $payload = null
    ) {
    }

    /**
     * Create a new pipeline instance
     * 
     * This method allows you to create a new pipeline instance with an optional initial payload.
     * 
     * @param mixed $payload The initial payload to be processed by the pipeline.
     * @return self A new instance of the Pipeline class.
     */
    public static function make(mixed $payload = null): self
    {
        return new self($payload);
    }

    /**
     * Set the initial payload
     * 
     * This method allows you to set the initial payload that will be processed by the pipeline.
     * 
     * @param mixed $payload The initial payload to be processed by the pipeline.
     * @return self The current instance of the Pipeline class for method chaining.
     */
    public function send(mixed $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * Add a pipe to the pipeline
     * 
     * This method allows you to add one or more pipes to the pipeline.
     * Pipes can be specified as callables, class names, or arrays representing class methods.
     * 
     * @param callable|string|array ...$pipes One or more pipes to be added to the pipeline.
     * @return self The current instance of the Pipeline class for method chaining.
     */
    public function through(callable|string|array ...$pipes): self
    {
        foreach ($pipes as $pipe) {
            $this->pipes[] = $this->resolvePipe($pipe);
        }
        return $this;
    }

    /**
     * Add a pipe (alias for through)
     * 
     * This method allows you to add a pipe to the pipeline.
     * It is an alias for the `through` method, providing a more concise way to add pipes.
     * 
     * @param callable|string|array ...$pipes One or more pipes to be added to the pipeline.
     * @return self The current instance of the Pipeline class for method chaining.
     */
    public function pipe(callable|string|array ...$pipes): self
    {
        return $this->through(...$pipes);
    }

    /**
     * Add middleware that wraps around the entire pipeline
     * 
     * This method allows you to add middleware functions that will be executed
     * before and after the main pipeline execution.
     * 
     * @param callable $middleware The middleware function to be added.
     * @return self The current instance of the Pipeline class for method chaining.
     */
    public function middleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Conditionally add pipes
     * 
     * This method allows you to conditionally add pipes to the pipeline based on a boolean condition.
     * If the condition is true, the specified pipes will be added.
     * 
     * @param bool $condition The condition to check.
     * @param callable|string ...$pipes One or more pipes to be added if the condition is true.
     */
    public function when(bool $condition, callable|string ...$pipes): self
    {
        if ($condition) {
            $this->through(...$pipes);
        }
        return $this;
    }

    /**
     * Conditionally add pipes (inverse of when)
     * 
     * This method allows you to conditionally add pipes to the pipeline based on a boolean condition.
     * If the condition is false, the specified pipes will be added.
     * 
     * @param bool $condition The condition to check.
     * @param callable|string ...$pipes One or more pipes to be added if the condition
     */
    public function unless(bool $condition, callable|string ...$pipes): self
    {
        return $this->when(!$condition, ...$pipes);
    }

    /**
     * Set error handler
     * 
     * This method allows you to set a custom error handler that will be called
     * if an error occurs during pipeline execution.
     * 
     * @param Closure $handler The error handler function that will be called with the exception, payload, and context.
     * @param bool $stopOnError Whether to stop execution on the first error encountered.
     */
    public function onError(Closure $handler, bool $stopOnError = true): self
    {
        $this->errorHandler = $handler;
        $this->stopOnError = $stopOnError;
        return $this;
    }

    /**
     * Add context data
     * 
     * This method allows you to add context data that will be available to all pipes and middleware.
     * 
     * @param array $context An associative array of context data to be added.
     * @return self The current instance of the Pipeline class for method chaining.
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * Enable debug mode
     * 
     * This method allows you to enable or disable debug mode for the pipeline.
     * Debug mode will log pipeline operations, which can be useful for debugging and monitoring.
     * 
     * @param bool $debug Whether to enable debug mode (default is true).
     * @return self The current instance of the Pipeline class for method chaining.
     */
    public function debug(bool $debug = true): self
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * Get debug logs
     * 
     * This method returns the debug logs collected during pipeline execution.
     * It can be useful for debugging and monitoring the pipeline's operations.
     * 
     * @return array An array of debug logs, each containing a timestamp, log level, message, and context.
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * Execute the pipeline and return the result
     * 
     * This method executes the pipeline with the provided destination callable.
     * If a destination is provided, it will be added to the end of the pipeline.
     * 
     * @param callable|null $destination The destination callable to be executed at the end of the pipeline.
     * @return mixed The result of the pipeline execution, or the result of the destination callable if provided.
     */
    public function then(?callable $destination = null): mixed
    {
        try {
            if ($destination) {
                $this->pipes[] = $this->resolvePipe($destination);
            }

            $result = $this->executeMiddleware(
                fn() => $this->executePipeline($this->payload)
            );

            $this->log('Pipeline completed successfully');
            return $result;

        } catch (Throwable $e) {
            $this->log("Pipeline failed: {$e->getMessage()}", 'error');

            if ($this->errorHandler) {
                return ($this->errorHandler)($e, $this->payload, $this->context);
            }

            throw $e;
        }
    }

    /**
     * Execute pipeline and return the final result (alias for then)
     * 
     * This method is an alias for the `then` method, allowing you to execute the pipeline
     * and return the final result.
     * 
     * @param callable|null $destination The destination callable to be executed at the end of the pipeline.
     * @return mixed The result of the pipeline execution, or the result of the destination callable if provided.
     */
    public function execute(?callable $destination = null): mixed
    {
        return $this->then($destination);
    }

    /**
     * Execute each pipe and collect all results
     * 
     * This method executes each pipe in the pipeline sequentially and collects the results.
     * If a pipe fails, it will log the error and continue to the next pipe unless `stopOnError` is set to true.
     * 
     * @return array An array of results from each pipe execution, or null if a pipe failed and `stopOnError` is true.
     */
    public function collect(): array
    {
        $results = [];
        $payload = $this->payload;

        foreach ($this->pipes as $index => $pipe) {
            try {
                $result = $this->executePipe($pipe, $payload, fn($p) => $p);
                $results[] = $result;
                $payload = $result;

                $this->log("Pipe {$index} executed", 'info', ['result' => $result]);
            } catch (Throwable $e) {
                $this->log("Pipe {$index} failed: {$e->getMessage()}", 'error');

                if ($this->stopOnError) {
                    throw $e;
                }

                $results[] = null;
            }
        }

        return $results;
    }

    /**
     * Execute the pipeline asynchronously (returns a Generator)
     * 
     * This method executes each pipe in the pipeline asynchronously, yielding results as they are processed.
     * If a pipe fails, it will log the error and yield null unless `stopOnError` is set to true.
     * 
     * @return Generator Yields the result of each pipe execution.
     */
    public function async(): Generator
    {
        $payload = $this->payload;

        foreach ($this->pipes as $index => $pipe) {
            try {
                $payload = $this->executePipe($pipe, $payload, fn($p) => $p);
                $this->log("Async pipe {$index} executed", 'info');
                yield $index => $payload;
            } catch (Throwable $e) {
                $this->log("Async pipe {$index} failed: {$e->getMessage()}", 'error');

                if ($this->stopOnError) {
                    throw $e;
                }

                yield $index => null;
            }
        }
    }

    /**
     * Execute the pipeline with middleware
     * 
     * This method executes the pipeline with the added middleware functions.
     * The middleware will wrap around the core pipeline execution, allowing for pre- and post-processing
     * 
     * @param Closure $core The core pipeline function to be executed, which will be wrapped by the middleware.
     * @return mixed The result of the pipeline execution after applying middleware.
     */
    private function executeMiddleware(Closure $core): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            fn($next, $middleware) => fn($payload) => $middleware($payload, $next),
            $core
        );

        return $pipeline($this->payload);
    }

    /**
     * Execute the core pipeline
     * 
     * This method executes the core pipeline by iterating through each pipe and calling it with the payload.
     * If a pipe is an instance of PipeInterface, it will call the `handle`
     * method, otherwise it will call the pipe as a regular callable.
     * 
     * @return mixed The result of the pipeline execution, or the result of the destination callable if provided.
     */
    private function executePipeline(mixed $payload): mixed
    {
        $next = array_reduce(
            array_reverse($this->pipes),
            fn($next, $pipe) => fn($payload) => $this->executePipe($pipe, $payload, $next),
            fn($payload) => $payload
        );

        return $next($payload);
    }

    /**
     * Execute a single pipe
     * 
     * This method executes a single pipe with the provided payload and next callable.
     * If the pipe is an instance of PipeInterface, it will call the `handle`
     * method, otherwise it will call the pipe as a regular callable.
     * 
     * @param callable $pipe The pipe to be executed.
     * @param mixed $payload The payload to be processed by the pipe.
     * @param Closure $next The next callable in the pipeline to be executed after this pipe.
     * 
     * @return mixed The result of the pipe execution, or the result of the next callable if the pipe does not call it.
     */
    private function executePipe(callable $pipe, mixed $payload, Closure $next): mixed
    {
        if ($pipe instanceof PipeInterface) {
            return $pipe->handle($payload, $next);
        }

        // For regular callables
        $result = $pipe($payload, $next, $this->context);

        // If the pipe doesn't call $next, we call it with the result
        return $result ?? $next($payload);
    }

    /**
     * Resolve pipe from various formats
     * 
     * This method resolves a pipe from different formats:
     * - Callable: Directly returns the callable
     * - String: Resolves to a class name and returns an instance or method
     * - Array: Resolves to a class and method with parameters
     * 
     * @param callable|string|array $pipe The pipe to be resolved.
     * @return callable The resolved pipe as a callable.
     */
    private function resolvePipe(callable|string|array $pipe): callable
    {
        return match (true) {
            is_callable($pipe) => $pipe,
            is_string($pipe) => $this->resolveStringPipe($pipe),
            is_array($pipe) => $this->resolveArrayPipe($pipe),
            default => throw new InvalidArgumentException('Invalid pipe type')
        };
    }

    /**
     * Resolve string-based pipes (class names)
     * 
     * This method resolves a string-based pipe, which is expected to be a class name.
     * It will create an instance of the class and return it as a callable.
     * 
     * @return callable The resolved pipe as a callable.
     */
    private function resolveStringPipe(string $pipe): callable
    {
        if (class_exists($pipe)) {
            $instance = new $pipe();

            if ($instance instanceof PipeInterface) {
                return $instance;
            }

            if (method_exists($instance, '__invoke')) {
                return $instance;
            }

            if (method_exists($instance, 'handle')) {
                return [$instance, 'handle'];
            }
        }

        throw new InvalidArgumentException("Cannot resolve pipe: {$pipe}");
    }

    /**
     * Resolve array-based pipes [class, method, parameters]
     * 
     * This method resolves an array-based pipe, which is expected to contain a class name,
     * method name, and optional parameters.
     * 
     * @return callable The resolved pipe as a callable.
     */
    private function resolveArrayPipe(array $pipe): callable
    {
        [$class, $method, $parameters] = array_pad($pipe, 3, []);

        $instance = is_string($class) ? new $class(...$parameters) : $class;

        return [$instance, $method];
    }

    /**
     * Log pipeline operations
     * 
     * This method logs pipeline operations, including errors and debug information.
     * It collects logs in an array, which can be retrieved later for debugging purposes.
     * 
     * @param string $message The log message to be recorded.
     * @param string $level The log level (default is 'info').
     * @param array $context Additional context information to be included in the log.
     * 
     * @return void
     */
    private function log(string $message, string $level = 'info', array $context = []): void
    {
        if (!$this->debug) {
            return;
        }

        $this->logs[] = [
            'timestamp' => microtime(true),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
    }

    /**
     * Reset the pipeline for reuse
     * 
     * This method resets the pipeline's state, clearing all pipes, middleware, error handlers, context, logs, and payload.
     * It allows the pipeline to be reused without carrying over previous state.
     * 
     * @return self The current instance of the Pipeline class for method chaining.
     */
    public function reset(): self
    {
        $this->pipes = [];
        $this->middleware = [];
        $this->errorHandler = null;
        $this->context = [];
        $this->logs = [];
        $this->payload = null;

        return $this; // return the current instance for method chaining
    }

    /**
     * Clone the pipeline
     * 
     * This method creates a clone of the current pipeline instance, preserving its state.
     * It allows you to create a new pipeline with the same configuration without affecting the original instance.
     * 
     * @return self A new instance of the Pipeline class with the same state as the current instance.
     */
    public function clone(): self
    {
        $clone = new self($this->payload);
        $clone->pipes = $this->pipes;
        $clone->middleware = $this->middleware;
        $clone->errorHandler = $this->errorHandler;
        $clone->stopOnError = $this->stopOnError;
        $clone->context = $this->context;
        $clone->debug = $this->debug;

        return $clone; // return the cloned instance
    }
}