<?php

namespace Spark\Contracts\Utils;

interface CacheUtilContract
{
    public function has(string $key, bool $eraseExpired = false): bool;

    public function store(string $key, mixed $data, ?string $expire = null): self;

    public function load(string $key, callable $callback, ?string $expire = null): mixed;

    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed;

    public function erase(string|array $keys): self;

    public function flush(): self;

}