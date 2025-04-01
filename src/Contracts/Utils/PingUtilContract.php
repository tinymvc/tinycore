<?php

namespace Spark\Contracts\Utils;

interface PingUtilContract
{
    public function option(int $key, mixed $value): self;

    public function header(string $key, string $value): self;

    public function send(string $url, array $params = []): array;
}