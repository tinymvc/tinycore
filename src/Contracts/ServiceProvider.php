<?php

namespace Spark\Contracts;

use Spark\Container;

/**
 * Interface ServiceProvider
 *
 * Defines a contract for service providers to register services into the container.
 * 
 * @package Spark\Contracts
 */
interface ServiceProvider
{
    /**
     * Register services into the container.
     *
     * @param Container $container
     * @return void
     */
    public function register(Container $container): void;
}