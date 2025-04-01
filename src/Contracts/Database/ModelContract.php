<?php

namespace Spark\Contracts\Database;

use Spark\Database\QueryBuilder;

interface ModelContract
{
    public static function query(): QueryBuilder;

    public static function find($value): false|static;

    public static function load(array $data): static;

    public function save(): int|bool;

    public function remove(): bool;

    public function toArray(): array;
}