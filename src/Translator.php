<?php

namespace Spark;

use Spark\Contracts\TranslatorContract;
use Spark\Support\Traits\Macroable;

use function count;
use function in_array;
use function is_array;
use function sprintf;

/**
 * Class Translator
 * 
 * Translator class for handling multilingual text translations.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Translator implements TranslatorContract
{
    use Macroable;

    /**
     * Holds translated texts.
     * 
     * @var array
     */
    private array $translatedTexts = [];

    /**
     * List of deferred language files to be loaded.
     * 
     * @var array
     */
    private array $deferredLanguageFiles = [];

    /**
     * Loaded language files to prevent duplicate loading.
     * 
     * @var array
     */
    private array $loadedFiles = [];

    /**
     * Constructor for the translator class.
     * 
     * Optionally loads a language file during initialization.
     * 
     * @param string|null $lang_file Path to the language file. Defaults to the file based on environment settings.
     */
    public function __construct(?string $lang_file = null)
    {
        // Determine the default language file if none is provided
        if ($lang_file === null) {
            $lang_file = sprintf('%s/%s.php', config('lang_dir'), config('lang', 'en'));
        }

        $this->addLanguageFile($lang_file);
    }

    /**
     * Merges the given translations with the existing ones.
     * 
     * Useful for adding or overriding translations for a specific context.
     * 
     * @param array $translatedTexts The translations to be merged.
     * @return void
     */
    public function mergeTranslatedTexts(array $translatedTexts): void
    {
        $this->translatedTexts = array_merge($this->translatedTexts, $translatedTexts);
    }

    /**
     * Adds a language file to the list of deferred language files.
     *
     * If the prepend flag is true, the language file is added to the beginning
     * of the list. Otherwise, it is added to the end of the list.
     *
     * @param string $file The path to the language file to add.
     * @param bool $prepend Whether to add the language file to the beginning of the list.
     * @return void
     */
    public function addLanguageFile(string $file, bool $prepend = false): void
    {
        $file = dir_path($file);

        if ($prepend) {
            array_unshift($this->deferredLanguageFiles, $file);
            return;
        }

        $this->deferredLanguageFiles[] = $file;
    }

    /**
     * Sets the translations for the translator.
     * 
     * Replaces any existing translations with the provided array of translated texts.
     * 
     * @param array $translatedTexts The translations to set.
     * @return void
     */
    public function setTranslatedTexts(array $translatedTexts): void
    {
        $this->translatedTexts = $translatedTexts;
    }

    /**
     * Translates a given text based on the local translations or returns the original text if translation is unavailable.
     * Supports pluralization and argument substitution with both %s and :name styles.
     *
     * @param string $text The text to be translated.
     * @param mixed $arg The number to determine pluralization or replace placeholder in the translated text, or associative array for :name style.
     * @param array $args An array of arguments to replace placeholders in the translated text.
     * @param array $args2 An array of arguments to replace plural placeholders in the translated text.
     * @return string The translated text with any placeholders replaced by the provided arguments.
     */
    public function translate(string $text, mixed $arg = null, array $args = [], array $args2 = []): string
    {
        // Check if the text has a translation
        $translation = $this->getTranslatedText($text);

        // Determine if the translation has plural forms
        if (is_array($translation)) {
            $translation = $arg > 1 ? $translation[1] : $translation[0];
            $args = $arg > 1 && !empty($args2) ? $args2 : $args;
        } elseif (!empty($args) && !empty($args2)) {
            $args = $arg > 1 ? $args2 : $args;
        }

        // Handle different argument styles
        if ($arg !== null && empty($args)) {
            $args = is_array($arg) ? $arg : [$arg];
        }

        // Check if we're using :name style placeholders
        if ($this->hasNamedPlaceholders($translation)) {
            return $this->replaceNamedPlaceholders($translation, $args);
        }

        // Use vsprintf for %s style placeholders (backward compatibility)
        try {
            return vsprintf($translation, $args);
        } catch (\Throwable $e) {
            // If vsprintf fails, return the translation as-is
            return $translation;
        }
    }

    /**
     * Checks if the translation text contains :name style placeholders.
     *
     * @param string $text The text to check.
     * @return bool True if the text contains :name style placeholders, false otherwise.
     */
    private function hasNamedPlaceholders(string $text): bool
    {
        return preg_match('/:\w+/', $text) === 1;
    }

    /**
     * Replaces :name style placeholders with values from associative or indexed array.
     *
     * @param string $text The text containing :name placeholders.
     * @param array $args The arguments to replace placeholders with.
     * @return string The text with placeholders replaced.
     */
    private function replaceNamedPlaceholders(string $text, array $args): string
    {
        // If args is associative array, replace by key names
        if (!array_is_list($args)) {
            foreach ($args as $key => $value) {
                $text = str_replace(":$key", $value, $text);
            }
        } else {
            // If args is indexed array, replace placeholders in order they appear
            preg_match_all('/:\w+/', $text, $matches);
            foreach ($matches[0] as $index => $placeholder) {
                if (isset($args[$index])) {
                    $text = str_replace($placeholder, $args[$index], $text);
                }
            }
        }

        return $text;
    }

    /**
     * Gets the translated text based on the given text.
     * 
     * Checks if the given text has a translation in the loaded language files.
     * If the translation is not found, it loads deferred language files until
     * the translation is found or all files are loaded.
     * 
     * @param string $text The text to be translated.
     * @return string|array The translated text or an array of plural translations.
     */
    public function getTranslatedText(string $text): string|array
    {
        // Load all deferred language files until translation is found
        while (!isset($this->translatedTexts[$text]) && !empty($this->deferredLanguageFiles)) {
            $lang_file = array_shift($this->deferredLanguageFiles);

            // Skip if file was already loaded
            if (in_array($lang_file, $this->loadedFiles, true)) {
                continue;
            }

            // Check if the language file exists
            if (file_exists($lang_file)) {
                $this->loadLanguageFile($lang_file);
            }
        }

        // Return the translated text if found, else return the original text
        return $this->translatedTexts[$text] ?? $text;
    }

    /**
     * Load a language file and merge its translations.
     * 
     * @param string $lang_file Path to the language file.
     * @return void
     */
    private function loadLanguageFile(string $lang_file): void
    {
        if (is_debug_mode()) {
            $started = microtime(true);
            $startedMemory = memory_get_usage(true);

            // Load and validate translations
            $translations = require $lang_file;

            if (!is_array($translations)) {
                trigger_error("Language file [{$lang_file}] must return an array.", E_USER_WARNING);
                $translations = [];
            }

            // Merge the loaded translations with the existing ones
            $this->mergeTranslatedTexts($translations);
            $this->loadedFiles[] = $lang_file;

            $loadTime = round((microtime(true) - $started) * 1000, 6);
            event('app:translator.loadedLanguageFile', [
                'file' => $lang_file,
                'load_time' => $loadTime,
                'memory_before' => $startedMemory
            ]);
        } else {
            $translations = require $lang_file;

            if (!is_array($translations)) {
                $translations = [];
            }

            $this->mergeTranslatedTexts($translations);
            $this->loadedFiles[] = $lang_file;
        }
    }

    /**
     * Check if a translation exists for the given key.
     * 
     * @param string $key The translation key to check.
     * @return bool True if translation exists, false otherwise.
     */
    public function has(string $key): bool
    {
        // Load pending files to check if translation exists
        $translation = $this->getTranslatedText($key);

        // Check if we got the actual translation or just the key back
        return $translation !== $key || isset($this->translatedTexts[$key]);
    }

    /**
     * Get a translation by key without placeholder replacement.
     * 
     * @param string $key The translation key.
     * @param mixed $default The default value if translation doesn't exist.
     * @return string|array The translation or default value.
     */
    public function get(string $key, mixed $default = null): string|array
    {
        $translation = $this->getTranslatedText($key);

        // If we got the key back and it's not in translations, return default
        if ($translation === $key && !isset($this->translatedTexts[$key])) {
            return $default ?? $key;
        }

        return $translation;
    }

    /**
     * Handle pluralization using Laravel-style choice syntax.
     * 
     * @param string $key The translation key.
     * @param int|float $number The count for pluralization.
     * @param array $replace Replacement values.
     * @return string The pluralized translation.
     */
    public function choice(string $key, int|float $number, array $replace = []): string
    {
        return $this->translate($key, $number, $replace);
    }

    /**
     * Get all translations.
     * 
     * @return array All loaded translations.
     */
    public function all(): array
    {
        // Load all deferred files first
        while (!empty($this->deferredLanguageFiles)) {
            $lang_file = array_shift($this->deferredLanguageFiles);

            if (file_exists($lang_file) && !in_array($lang_file, $this->loadedFiles, true)) {
                $this->loadLanguageFile($lang_file);
            }
        }

        return $this->translatedTexts;
    }

    /**
     * Get all loaded language files.
     * 
     * @return array List of loaded language file paths.
     */
    public function getLoadedFiles(): array
    {
        return $this->loadedFiles;
    }

    /**
     * Check if any language files have been loaded.
     * 
     * @return bool True if files have been loaded, false otherwise.
     */
    public function hasLoadedFiles(): bool
    {
        return !empty($this->loadedFiles);
    }

    /**
     * Get count of loaded translations.
     * 
     * @return int Number of loaded translations.
     */
    public function count(): int
    {
        return count($this->translatedTexts);
    }

    /**
     * Clear all loaded translations and reset state.
     * 
     * @return void
     */
    public function flush(): void
    {
        $this->translatedTexts = [];
        $this->loadedFiles = [];
        $this->deferredLanguageFiles = [];
    }

    /**
     * Alias for translate method.
     * 
     * @param string $text The text to be translated.
     * @param mixed $arg Arguments for placeholder replacement.
     * @param array $args Additional arguments.
     * @param array $args2 Plural arguments.
     * @return string The translated text.
     */
    public function trans(string $text, mixed $arg = null, array $args = [], array $args2 = []): string
    {
        return $this->translate($text, $arg, $args, $args2);
    }

    /**
     * Translate with named placeholders (Laravel-style).
     * 
     * @param string $key The translation key.
     * @param array $replace Associative array of replacements.
     * @return string The translated text with replacements.
     */
    public function transWithReplace(string $key, array $replace = []): string
    {
        return $this->translate($key, $replace);
    }
}
