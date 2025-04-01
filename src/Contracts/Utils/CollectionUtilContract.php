<?php

namespace Spark\Contracts\Utils;

interface CollectionUtilContract
{
    public static function make(array $items = []): self;

    public function all(): array;

    public function get(int|string $key, $default = null): mixed;

    public function has(int|string $key): bool;

    public function add(int|string $key, $value): self;

    public function remove(int|string $key): self;

    public function multiSort(string $column, bool $desc = false): self;

    public function filter(callable $callback): self;

    public function map(callable $callback): self;

    public function mapK(callable $callback): self;

    public function pluck(string $key): self;

    public function except(array $keys): self;

    public function only(array $keys): self;

    public function find(callable $callback, $default = null): mixed;

    public function toJson(...$args): string;

    public function toString(string $separator = ''): string;
}