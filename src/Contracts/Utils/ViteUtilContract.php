<?php

namespace Spark\Contracts\Utils;

interface ViteUtilContract
{
    public function isRunning(string $entry): bool;

    public function asset(string $entry): string;

    public function hasManifest(): bool;

    public function getManifest(): array;

    public function __toString(): string;
}