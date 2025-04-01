<?php

namespace Spark\Contracts\Utils;

interface TracerUtilContract
{
    public static function trace(): void;

    public function renderError(string $type, string $message, string $file, int $line, array $trace = []): void;
}