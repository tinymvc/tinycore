<?php

namespace Spark\Contracts\Database;

interface QueryBuilderContract
{
    public function table(string $table): self;

    public function insert(array $data, array $config = []): int;

    public function where(string|array $column = null, ?string $operator = null, mixed $value = null, ?string $type = null): self;

    public function update(array $data, mixed $where = null): bool;

    public function delete(mixed $where = null): bool;

    public function select(array|string $fields = '*'): self;

    public function join(string $table, string $condition): self;

    public function first(): mixed;

    public function last(): mixed;

    public function result(): array;

    public function count(): int;
}