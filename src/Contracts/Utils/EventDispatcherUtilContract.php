<?php

namespace Spark\Contracts\Utils;

interface EventDispatcherUtilContract
{
    public function addListener(string $eventName, callable $listener): void;

    public function dispatch(string $eventName, ...$args): void;
}