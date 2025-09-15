<?php

namespace Spark\Facades;

use Spark\Foundation\Application;

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
     * Handle dynamic static method calls into the object instance.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     * @throws \BadMethodCallException
     */
    public static function __callStatic($method, $args)
    {
        $instance = Application::$app->make(static::getFacadeAccessor());

        if (method_exists($instance, $method)) {
            return $instance->$method(...$args);
        }

        throw new \BadMethodCallException("Method {$method} does not exist on " . static::getFacadeAccessor() . ".");
    }
}