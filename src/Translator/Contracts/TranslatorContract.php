<?php

namespace Spark\Translator\Contracts;

interface TranslatorContract
{
    public function get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string|array;

    public function choice(string $key, int|float|array|\Countable $number, array $replace = [], ?string $locale = null): string;

    public function format(string $text, mixed $arg = null, array $args = [], array $args2 = [], ?string $locale = null): string;

    public function has(string $key, ?string $locale = null, bool $fallback = true): bool;

    public function getLocale(): string;

    public function setLocale(string $locale): void;

    public function getFallback(): ?string;

    public function setFallback(?string $locale): void;

    public function addLines(array $lines, ?string $locale = null, string $group = '*'): void;

    public function addLanguageFile(string $file, bool $prepend = false, ?string $locale = null): void;

    public function addPath(string $path, bool $prepend = false): static;

    public function withLocale(string $locale): static;

    public function all(?string $locale = null, string $group = '*'): array;

    public function handleMissingKeysUsing(?callable $callback): static;

    public function flush(): void;
}
