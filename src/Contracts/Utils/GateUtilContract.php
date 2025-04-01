<?php

namespace Spark\Contracts\Utils;

interface GateUtilContract
{
    public function define(string $ability, callable $callback): void;

    public function allows(string $ability, mixed ...$arguments): bool;

    public function denies(string $ability, mixed ...$arguments): bool;

    public function authorize(string $ability, mixed ...$arguments): void;

}