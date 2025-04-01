<?php

namespace Spark\Contracts\Foundation;

use Spark\Container;

interface ApplicationContract
{
    public static function make(string $path, array $env = []): self;

    public function getContainer(): Container;

    public function getPath(): string;

    public function getEnv(string $key, $default = null): mixed;

    public function withContainer(callable $callback): self;

    public function withRouter(callable $callback): self;

    public function withMiddleware(callable $callback): self;

    public function run(): void;
}