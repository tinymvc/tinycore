<?php

namespace Spark\Contracts;

use Spark\Contracts\Support\Arrayable;

/**
 * Interface for a container class.
 *
 * This interface outlines the methods that must be implemented
 * by a container class.
 *
 * @package Spark\Contracts
 */
interface ContainerContract
{
    /**
     * Bind a class or interface to a concrete implementation.
     *
     * The concrete implementation can be a class, a closure, or an interface.
     * If no concrete implementation is given, the abstract name will be used.
     *
     * @param string $abstract The abstract name of the class or interface.
     * @param callable|string|null $concrete The concrete implementation.
     */
    public function bind(string $abstract, callable|string|null $concrete = null): void;

    /**
     * Register a binding as a singleton.
     *
     * A singleton binding returns the same instance each time it is
     * requested. The concrete implementation can be a class, a closure, or an
     * interface. If no concrete implementation is given, the abstract name will
     * be used.
     *
     * @param string $abstract The abstract name of the class or interface.
     * @param callable|string|null $concrete The concrete implementation.
     */
    public function singleton(string $abstract, callable|string|null $concrete = null): void;

    /**
     * Register an alias for a given abstract name.
     *
     * @param string $alias The alias name.
     * @param string $abstract The abstract name.
     */
    public function alias(string $alias, string $abstract): void;

    /**
     * Registers a service provider.
     *
     * The service provider will register its bindings, and will be tracked
     * in the container.
     *
     * @param object $provider The service provider to register.
     */
    public function addServiceProvider($provider): void;

    /**
     * Boots all registered service providers.
     * 
     * This method calls the `boot` method on each registered service
     * provider to initialize them.
     * 
     * @return void
     */
    public function bootServiceProviders(): void;

    /**
     * Check if a binding exists.
     *
     * @param string $abstract The abstract name of the class or interface.
     *
     * @return bool True if the binding exists, false otherwise.
     */
    public function has(string $abstract): bool;

    /**
     * Resolve a binding.
     *
     * If the binding is a singleton, returns the same instance each time it is
     * requested. If the binding is not a singleton, a new instance is created
     * each time it is requested.
     *
     * @param string $abstract The abstract name of the class or interface.
     *
     * @return mixed The resolved instance.
     */
    public function get(string $abstract): mixed;

    /**
     * Resolve a binding with parameters.
     *
     * If the binding is a singleton, returns the same instance each time it is
     * requested. If the binding is not a singleton, a new instance is created
     * each time it is requested.
     *
     * @param string $abstract The abstract name of the class or interface.
     * @param Arrayable|array $parameters The parameters to pass to the constructor.
     *
     * @return mixed The resolved instance.
     */
    public function make(string $abstract, Arrayable|array $parameters = []): mixed;

    /**
     * Calls a class method or a closure with the given parameters.
     *
     * This method supports three formats to call a class method or a closure.
     *
     * 1. Class name and method name as a string with '@' separator.
     *    Example: 'ClassName@methodName'.
     * 2. Class name and method name as an array.
     *    Example: ['ClassName', 'methodName'].
     * 3. A closure or callable.
     *
     * The method resolves the instance of the class if it is not given, and
     * resolves the method parameters by using the given parameters and the
     * container if the parameter is not given.
     *
     * @param array|string|callable $abstract The class name, method name or a closure.
     * @param Arrayable|array $parameters The parameters to pass to the method or closure.
     *
     * @return mixed The result of calling the method or closure.
     */
    public function call(array|string|callable $abstract, Arrayable|array $parameters = []): mixed;

    /**
     * Register an existing instance as a binding.
     *
     * @param string $abstract The abstract name of the class or interface.
     * @param mixed $instance The instance to register.
     */
    public function instance(string $abstract, mixed $instance): void;

    /**
     * Check if a binding is registered.
     *
     * @param string $abstract The abstract name of the class or interface.
     *
     * @return bool True if the binding is registered, false otherwise.
     */
    public function bound(string $abstract): bool;

    /**
     * Check if a binding has been resolved.
     *
     * @param string $abstract The abstract name of the class or interface.
     *
     * @return bool True if the binding has been resolved, false otherwise.
     */
    public function resolved(string $abstract): bool;

    /**
     * Remove a binding from the container.
     *
     * @param string $abstract The abstract name of the class or interface.
     */
    public function forget(string $abstract): void;

    /**
     * Reset a binding in the container.
     *
     * This method removes the existing binding and re-registers it with
     * the given concrete implementation.
     *
     * @param string $abstract The abstract name of the class or interface.
     * @param callable|string|null $concrete The new concrete implementation.
     */
    public function reset(string $abstract, callable|string|null $concrete = null): void;
}