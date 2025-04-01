<?php

namespace Spark\Contracts\Http;

interface RequestContract
{
    public function getMethod(): string;

    public function getPath(): string;

    public function getUrl(): string;

    public function getRootUrl(): string;

    public function getRouteParam(string $key, ?string $default = null): ?string;

    public function query(string $key, $default = null): ?string;

    public function post(string $key, $default = null): mixed;

    public function file(string $key, $default = null): mixed;

    public function hasFile(string $key): bool;

    public function all(array $filter = []): array;

    public function server(string $key, $default = null): ?string;

    public function header(string $name, $defaultValue = null): ?string;
}