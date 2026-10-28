<?php

namespace Spark;

use ArrayAccess;
use Countable;
use InvalidArgumentException;
use Spark\Contracts\TranslatorContract;
use Spark\Support\Arr;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use UnexpectedValueException;
use function array_key_exists;
use function count;
use function is_array;
use function is_string;

class Translator implements TranslatorContract, ArrayAccess, Countable
{
    use Macroable, Conditionable;

    private string $locale;
    private ?string $fallback;
    private array $catalogues = [];
    private array $languageFiles = [];
    private array $loadedFiles = [];
    private array $lines = [];

    public function __construct(?string $lang_file = null, ?string $locale = null, ?string $fallback = null)
    {
        $this->setLocale($locale ?? config('app.locale', 'en'));
        $this->setFallback($fallback ?? config('app.fallback_locale', 'en'));
        if ($lang_file !== null) {
            $this->languageFiles[$this->locale] = [dir_path($lang_file)];
        }
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->validateLocale($locale);
        $this->locale = $locale;
    }

    public function getFallback(): ?string
    {
        return $this->fallback;
    }

    public function setFallback(?string $locale): void
    {
        if ($locale !== null) {
            $this->validateLocale($locale);
        }
        $this->fallback = $locale;
    }

    /** Resolve exact or nested keys, then the fallback locale, then the key itself. */
    public function get(string $key, array $replace = [], ?string $locale = null): string|array
    {
        return $this->replace($this->find($key, $locale ?? $this->locale) ?? $key, $replace);
    }

    public function has(string $key, ?string $locale = null, bool $fallback = true): bool
    {
        return $this->find($key, $locale ?? $this->locale, $fallback) !== null;
    }

    public function choice(string $key, int|float $number, array $replace = [], ?string $locale = null): string
    {
        $line = $this->find($key, $locale ?? $this->locale) ?? $key;
        $line = $this->format($this->select($line, $number), [$number]);
        return $this->replace($line, ['count' => $number, ...$replace]);
    }

    public function addLines(array $lines, ?string $locale = null): void
    {
        $locale ??= $this->locale;
        $this->validateLocale($locale);
        $this->lines[$locale] = array_replace_recursive($this->lines[$locale] ?? [], $lines);
        unset($this->catalogues[$locale]);
    }

    public function addLanguageFile(string $file, bool $prepend = false): void
    {
        $files = $this->languageFiles[$this->locale] ?? [locale_dir($this->locale . '.php')];
        $file = dir_path($file);
        $this->languageFiles[$this->locale] = array_values(array_unique(
            $prepend ? [$file, ...$files] : [...$files, $file]
        ));
        unset($this->catalogues[$this->locale]);
    }

    public function all(?string $locale = null): array
    {
        $locale ??= $this->locale;
        $this->validateLocale($locale);
        if (!isset($this->catalogues[$locale])) {
            $catalogue = [];
            foreach ($this->languageFiles[$locale] ?? [locale_dir($locale . '.php')] as $file) {
                $catalogue = array_replace_recursive($catalogue, $this->load($file));
            }
            $this->catalogues[$locale] = array_replace_recursive($catalogue, $this->lines[$locale] ?? []);
        }
        return $this->catalogues[$locale];
    }

    /** Clear cached catalogues and runtime lines; configured files are loaded again on demand. */
    public function flush(): void
    {
        $this->catalogues = $this->loadedFiles = $this->lines = [];
    }

    public function count(): int
    {
        return count($this->all());
    }

    private function find(string $key, string $locale, bool $fallback = true): string|array|null
    {
        $line = Arr::get($this->all($locale), $key);
        if ($line === null && $fallback && $this->fallback !== null && $locale !== $this->fallback) {
            $line = Arr::get($this->all($this->fallback), $key);
        }
        if ($line !== null && !is_string($line) && !is_array($line)) {
            throw new UnexpectedValueException("Translation [$key] must be a string or array.");
        }
        return $line;
    }

    private function load(string $file): array
    {
        if (array_key_exists($file, $this->loadedFiles)) {
            return $this->loadedFiles[$file] ?? [];
        }
        if (!is_file($file)) {
            $this->loadedFiles[$file] = null;
            return [];
        }
        $started = microtime(true);
        $memory = memory_get_usage(true);
        $lines = require $file;
        if (!is_array($lines)) {
            throw new UnexpectedValueException("Language file [$file] must return an array.");
        }
        $this->loadedFiles[$file] = $lines;
        if (is_debug_mode()) {
            event('app:translator.loadedLanguageFile', [
                'file' => $file,
                'load_time' => round((microtime(true) - $started) * 1000, 6),
                'memory_before' => $memory,
            ]);
        }
        return $lines;
    }

    private function validateLocale(string $locale): void
    {
        if (!preg_match('/^[a-zA-Z]{2,8}(?:[-_][a-zA-Z0-9]{2,8})*$/D', $locale)) {
            throw new InvalidArgumentException('Invalid locale identifier.');
        }
    }

    private function replace(string|array $line, array $replace): string|array
    {
        if (is_array($line)) {
            return array_map(fn($value) => $this->replace($value, $replace), $line);
        }
        $pairs = [];
        foreach ($replace as $key => $value) {
            $value = (string) $value;
            $pairs[":$key"] = $value;
            if (is_string($key) && strtolower($key) === $key) {
                $pairs[':' . strtoupper($key)] = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
                $pairs[':' . ucfirst($key)] = function_exists('mb_convert_case') ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : ucwords($value);
            }
        }
        // Longest matches win; replacement values are never processed a second time.
        return strtr($line, $pairs);
    }

    private function select(string|array $line, int|float $number): string
    {
        if (is_array($line)) {
            return (string) ($number == 1
                ? ($line['one'] ?? $line[0] ?? $line['other'] ?? '')
                : ($line['other'] ?? $line[1] ?? $line['one'] ?? $line[0] ?? ''));
        }
        $forms = [];
        foreach (explode('|', $line) as $part) {
            if (preg_match('/^\s*\{(-?[\d.]+)\}\s*(.*)$/s', $part, $match)) {
                if ($number == (float) $match[1]) {
                    return $match[2];
                }
            } elseif (preg_match('/^\s*\[(-?[\d.]+|\*),\s*(-?[\d.]+|\*)\]\s*(.*)$/s', $part, $match)) {
                if (($match[1] === '*' || $number >= (float) $match[1]) && ($match[2] === '*' || $number <= (float) $match[2])) {
                    return $match[3];
                }
            } else {
                $forms[] = $part;
            }
        }
        return $forms[$number == 1 ? 0 : 1] ?? $forms[0] ?? $line;
    }

    private function format(string $line, array $args): string
    {
        if ($args === [] || !str_contains($line, '%')) {
            return $line;
        }
        try {
            return vsprintf($line, $args);
        } catch (\ValueError) {
            return $line;
        }
    }

    /** Compatibility for existing printf, positional replacement and plural-array calls. */
    public function translate(string $text, mixed $arg = null, array $args = [], array $args2 = []): string
    {
        $line = $this->getTranslatedText($text);
        $number = is_numeric($arg) ? (float) $arg : 1;
        if (is_array($line) || is_numeric($arg)) {
            $line = $this->select($line, $number);
        }
        $args = $args2 !== [] && $number != 1 ? $args2 : ($args ?: (is_array($arg) ? $arg : ($arg === null ? [] : [$arg])));
        if (preg_match_all('/:\w+/', $line, $matches) && $args !== []) {
            if (array_is_list($args)) {
                $pairs = [];
                foreach (array_values(array_unique($matches[0])) as $index => $placeholder) {
                    if (array_key_exists($index, $args)) {
                        $pairs[$placeholder] = (string) $args[$index];
                    }
                }
                return strtr($line, $pairs);
            }
            return $this->replace($line, $args);
        }
        return $this->format($line, $args);
    }

    public function getTranslatedText(string $text): string|array
    {
        return $this->get($text);
    }

    public function mergeTranslatedTexts(array $translatedTexts): void
    {
        $this->addLines($translatedTexts);
    }

    public function setTranslatedTexts(array $translatedTexts): void
    {
        $this->catalogues[$this->locale] = $translatedTexts;
        $this->lines[$this->locale] = $translatedTexts;
    }

    public function getLoadedFiles(): array
    {
        return array_keys(array_filter($this->loadedFiles, fn($lines) => $lines !== null));
    }

    public function hasLoadedFiles(): bool
    {
        return $this->getLoadedFiles() !== [];
    }

    public function trans(string $text, mixed $arg = null, array $args = [], array $args2 = []): string
    {
        return $this->translate($text, $arg, $args, $args2);
    }

    public function transWithReplace(string $key, array $replace = []): string
    {
        return $this->translate($key, $replace);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_string($offset)) {
            $this->addLines([$offset => $value]);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $lines = $this->all();

        Arr::forget($lines, (string) $offset);
        $this->setTranslatedTexts($lines);
    }
}
