<?php

namespace Spark\Contracts\Utils;

interface SessionUtilContract
{
    public function get(string $key, $default = null): mixed;

    public function set(string $key, $value): void;

    public function has(string $key): bool;

    public function delete(string $key): void;

    public function regenerate(bool $deleteOldSession = false): bool;

    public function destroy(): bool;

    public function flash(string $key, $value): void;

    public function getFlash(string $key, $default = null): mixed;
}