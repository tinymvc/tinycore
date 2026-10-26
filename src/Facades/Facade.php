<?php

namespace Spark\Facades;

use Spark\Foundation\Application;
use function is_object;

/**
 * Class Facade
 * 
 * This class serves as a base for creating facades in the application.
 * 
 * A facade is a static interface to classes that are available in the application's service container.
 * It provides a way to access these classes without needing to instantiate them directly.
 *
 * @package Spark\Facades
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
abstract class Facade
{
    /**
     * Get the registered name of the component.
     * 
     * @return string
     */
    abstract protected static function getFacadeAccessor(): string;

    /**
     * Resolve the facade root instance from the container.
     *
     * @return mixed
     */
    public static function getFacadeRoot(): mixed
    {
        if (Application::$app !== null) {
            return Application::$app->make(static::getFacadeAccessor());
        }

        return null;
    }

    /**
     * Handle dynamic static method calls into the object instance.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     * @throws \BadMethodCallException
     */
    public static function __callStatic(string $method, array $args)
    {
        $accessor = static::getFacadeAccessor();
        $instance = static::getFacadeRoot();

        if ($instance !== null && is_object($instance)) {
            if (
                method_exists($instance, $method) ||
                method_exists($instance, '__call') ||
                (method_exists($instance, 'hasMacro') && $instance->hasMacro($method))
            ) {
                return $instance->$method(...$args);
            }
        }

        if (class_exists($accessor) && method_exists($accessor, $method)) {
            return $accessor::$method(...$args);
        }

        throw new \BadMethodCallException("Method $method does not exist on $accessor.");
    }
}