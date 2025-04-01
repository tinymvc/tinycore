<?php

namespace Spark\Contracts\Http;

use Spark\Container;
use Spark\Http\Request;
use Spark\Http\Response;

interface MiddlewareContract
{
    public function register(string $abstract, callable|string|null $concrete = null): self;

    public function queue(array|string $abstract): self;

    public function process(Container $container, Request $request): ?Response;
}