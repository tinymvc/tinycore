<?php

namespace Spark\Contracts;

use Spark\Container;
use Spark\Http\Middleware;
use Spark\Http\Request;
use Spark\Http\Response;

interface RouterContract
{
    public function get(string $path, callable|string|array $callback): self;

    public function post(string $path, callable|string|array $callback): self;

    public function put(string $path, callable|string|array $callback): self;

    public function patch(string $path, callable|string|array $callback): self;

    public function delete(string $path, callable|string|array $callback): self;

    public function options(string $path, callable|string|array $callback): self;

    public function any(string $path, callable|string|array $callback): self;

    public function view(string $path, string $template): self;

    public function middleware(string|array $middleware): self;

    public function name(string $name): self;

    public function group(array $attributes, callable $callback): void;

    public function route(string $name, null|string|array $context = null): string;

    public function dispatch(Container $container, Middleware $middleware, Request $request): Response;
}