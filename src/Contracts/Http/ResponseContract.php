<?php

namespace Spark\Contracts\Http;

interface ResponseContract
{
    public function setContent(string $content): self;

    public function write(string $content): self;

    public function json(array $data, int $statusCode = 200, int $flags = 0, int $depth = 512): self;

    public function redirect(string $url, bool $replace = true, int $httpCode = 0): void;

    public function setStatusCode(int $statusCode): self;

    public function setHeader(string $key, string $value): self;

    public function send(): void;
}