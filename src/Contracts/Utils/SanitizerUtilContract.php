<?php

namespace Spark\Contracts\Utils;

interface SanitizerUtilContract
{
    public function email(string $key): ?string;

    public function text(string $key, bool $stripTags = true): ?string;

    public function number(string $key): ?int;

    public function float(string $key): ?float;

    public function boolean(string $key): ?bool;

    public function url(string $key): ?string;
}