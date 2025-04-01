<?php

namespace Spark\Contracts;

interface ViewContract
{
    public function get(string $key, $default = null): mixed;

    public function set(string $key, mixed $value): self;

    public function has(string $key): bool;

    public function layout(string $layout): self;

    public function render(string $template, array $context = []): string;

    public function component(string $component, array $context = []): string;

    public function include(string $template, array $context = []): string;
}