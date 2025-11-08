<?php

namespace Spark;

use Spark\Contracts\TranslatorContract;
use Spark\Support\Traits\Macroable;

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
    public function mergeTranslatedTexts(array $translatedTexts)
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
    public function setTranslatedTexts(array $translatedTexts)
    {
        $this->translatedTexts = $translatedTexts;
    }

    /**
     * Translates a given text based on the local translations or returns the original text if translation is unavailable.
     * Supports pluralization and argument substitution with both %s and :name styles.
     *
     * @param string $text The text to be translated.
     * @param $arg The number to determine pluralization or replace placeholder in the translated text, or associative array for :name style.
     * @param array $args An array of arguments to replace placeholders in the translated text.
     * @param array $args2 An array of arguments to replace plural placeholders in the translated text.
     * @return string The translated text with any placeholders replaced by the provided arguments.
     */
    public function translate(string $text, $arg = null, array $args = [], array $args2 = []): string
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
        return vsprintf($translation, $args);
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
     * If the translation is not found, it checks if there are any deferred language files
     * and loads the first one. If the translation is still not found, the original text is returned.
     * 
     * @param string $text The text to be translated.
     * @return string|array The translated text or an array of plural translations.
     */
    public function getTranslatedText(string $text): string|array
    {
        // Check if the translation is already loaded
        $translated = $this->translatedTexts[$text] ?? null;

        // If the translation is not found and there are deferred language files
        if ($translated === null && !empty($this->deferredLanguageFiles)) {
            // Load the first deferred language file
            $lang_file = array_shift($this->deferredLanguageFiles);

            // Check if the language file exists
            if (file_exists($lang_file)) {
                if (env('debug')) {
                    $started = microtime(true);
                    $startedMemory = memory_get_usage(true);

                    // Merge the loaded translations with the existing ones
                    $this->mergeTranslatedTexts(require $lang_file);

                    $loadTime = round((microtime(true) - $started) * 1000, 6);
                    event('app:translator.loadedLanguageFile', [
                        'file' => $lang_file,
                        'load_time' => $loadTime,
                        'memory_before' => $startedMemory
                    ]);
                } else {
                    $this->mergeTranslatedTexts(require $lang_file);
                }
            }

            // Try to get the translation again
            return $this->getTranslatedText($text);
        }

        // Return the translated text if found, else return the original text
        return $translated ?? $text;
    }
}
