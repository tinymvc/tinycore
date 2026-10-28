<?php

namespace Spark\Contracts;

interface TranslatorContract
{
    public function get(string $key, array $replace = [], ?string $locale = null): string|array;

    public function choice(string $key, int|float $number, array $replace = [], ?string $locale = null): string;

    public function has(string $key, ?string $locale = null, bool $fallback = true): bool;

    public function getLocale(): string;

    public function setLocale(string $locale): void;

    public function getFallback(): ?string;

    public function setFallback(?string $locale): void;

    public function addLines(array $lines, ?string $locale = null): void;

    public function addLanguageFile(string $file, bool $prepend = false): void;

    public function flush(): void;

    public function translate(string $text, mixed $arg = null, array $args = [], array $args2 = []): string;

    public function mergeTranslatedTexts(array $translatedTexts): void;
}
