<?php

namespace Spark\Contracts;

/**
 * Interface ServiceProvider
 *
 * Defines a contract for service providers to register services into the container.
 * 
 * @package Spark\Contracts
 */
interface ServiceProviderContract
{
    /**
     * Register services into the container.
     *
     * @return void
     */
    public function register(): void;
}