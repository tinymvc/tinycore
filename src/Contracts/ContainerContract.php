<?php

namespace Spark\Contracts;

interface ContainerContract
{
    public function bind(string $abstract, callable|string|null $concrete = null): void;

    public function singleton(string $abstract, callable|string|null $concrete = null): void;

    public function alias(string $alias, string $abstract): void;

    public function addServiceProvider($provider): void;

    public function bootServiceProviders(): void;

    public function has(string $abstract): bool;

    public function get(string $abstract): mixed;

    public function call(array|string|callable $abstract, array $parameters = []): mixed;
}