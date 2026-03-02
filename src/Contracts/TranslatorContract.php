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

    /**
     * Checks if a translation exists for the given key.
     *
     * @param string $key The key to check for translation.
     * @return bool True if a translation exists, false otherwise.
     */
    public function has(string $key): bool;

    /**
     * Translates a given key based on the local translations or returns the original key
     * 
     * @param string $key
     * @param int|float $number
     * @param array $replace
     * @return string The translated choice text.
     */
    public function choice(string $key, int|float $number, array $replace = []): string;

    /**
     * Flushes all loaded translations from memory.
     */
    public function flush(): void;

    /**
     * Merges the provided translated texts into the existing translations.
     *
     * @param array $translatedTexts An array of translated texts to be merged.
     */
    public function mergeTranslatedTexts(array $translatedTexts): void;

    /**
     * Adds a language file to the translator.
     *
     * @param string $file The path to the language file to be added.
     * @param bool $prepend Whether to prepend the translations from the file (default: false).
     */
    public function addLanguageFile(string $file, bool $prepend = false): void;
}