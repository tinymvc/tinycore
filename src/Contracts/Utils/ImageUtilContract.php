<?php

namespace Spark\Contracts\Utils;

interface ImageUtilContract
{
    public function compress(int $quality = 75, $destination = null): bool;

    public function resize(int $imgWidth, int $imgHeight, ?string $destination = null): bool;

    public function rotate(float $degrees): bool;
}