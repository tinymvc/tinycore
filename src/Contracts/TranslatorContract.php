<?php

namespace Spark\Contracts;

/**
 * Interface TranslatorUtilContract
 *
 * This interface defines the methods required by the TranslatorUtil class.
 */
interface TranslatorContract
{
    /**
     * Translates a given text based on the local translations or returns the original text
     * if translation is unavailable. Supports pluralization and argument substitution.
     *
     * @param string $text The text to be translated.
     * @param $arg The number to determine pluralization or replace placeholder in the translated text.
     * @param array $args An array of arguments to replace placeholders in the translated text.
     * @param array $args2 An array of arguments to replace plural placeholders in the translated text.
     * @return string The translated text with any placeholders replaced by the provided arguments.
     */
    public function translate(string $text, $arg = null, array $args = [], array $args2 = []): string;
}