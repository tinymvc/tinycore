<?php

namespace Spark\Contracts\Utils;

interface TranslatorUtilContract
{
    public function addLanguageFile(string $file, bool $prepend = false): void;

    public function translate(string $text, $arg = null, array $args = [], array $args2 = []): string;
}