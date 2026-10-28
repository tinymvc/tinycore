<?php

declare(strict_types=1);

namespace Spark\Translator;

use Closure;
use InvalidArgumentException;
use Spark\Support\Arr;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use Spark\Translator\Contracts\TranslatorContract;
use Stringable;
use UnexpectedValueException;
use function array_key_exists;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_scalar;
use function is_string;

/**
 * Layout (any number of lang directories, later ones override earlier ones):
 *
 *   lang/en.php            flat file  → "Welcome back, :name", "auth.failed" (legacy nested also works)
 *   lang/en/auth.php       group file → get('auth.failed')
 *   lang/bn/validation.php → get('validation.required.email')
 *
 * Group files are loaded lazily, once, and never merged unless several
 * directories provide the same file.
 */
class Translator implements TranslatorContract
{
    use Macroable, Conditionable;

    private string $locale;
    private ?string $fallback;

    /** @var string[] lang directories, lowest → highest priority */
    private array $paths = [];

    /** @var array<string, string[]> extra flat files per locale, lowest → highest priority */
    private array $files = [];

    /** @var array<string, array<string, array>> [locale][group] => lines (lazy) */
    private array $loaded = [];

    /** @var array<string, array<string, array>> [locale][group] => lines added at runtime */
    private array $runtime = [];

    /** @var array<string, array> Parsed files, shared across group cache rebuilds. */
    private array $loadedFiles = [];

    private ?Closure $missingHandler = null;
    private bool $reporting = false;

    public function __construct(string|array|null $paths = null, ?string $locale = null, ?string $fallback = null)
    {
        $this->setLocale($locale ?? config('app.locale', 'en'));
        $this->setFallback($fallback ?? config('app.fallback_locale', 'en'));

        foreach ((array) ($paths ?? locale_dir('')) as $path) {
            $this->addPath($path);
        }
    }

    // ------------------------------------------------------------------
    // Locale
    // ------------------------------------------------------------------

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

    /**
     * Scoped copy for long-running workers (Swoole / ReactPHP): never call
     * setLocale() on a shared instance when requests run concurrently.
     */
    public function withLocale(string $locale): static
    {
        $copy = clone $this;
        $copy->setLocale($locale);
        return $copy;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /** Resolve: locale → parent locale (en_GB → en) → fallback → the key itself. */
    public function get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string|array
    {
        $locale ??= $this->locale;
        $line = $this->lookup($key, $locale, $fallback);

        if ($line === null) {
            $this->reportMissing($key, $locale);
            $line = $key;
        }

        return $this->replace($line, $replace);
    }

    public function has(string $key, ?string $locale = null, bool $fallback = true): bool
    {
        return $this->lookup($key, $locale ?? $this->locale, $fallback) !== null;
    }

    public function choice(string $key, int|float|array|\Countable $number, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;
        if (is_countable($number)) {
            $number = count($number);
        }

        if (is_float($number) && !is_finite($number)) {
            throw new InvalidArgumentException('Plural counts must be finite.');
        }

        $line = $this->lookup($key, $locale, true, $resolvedLocale);
        if ($line === null) {
            $this->reportMissing($key, $locale);
            return $this->replace($key, [...$replace, 'count' => $number]);
        }

        return $this->replace($this->select($line, $number, $resolvedLocale), [...$replace, 'count' => $number]);
    }

    /** Resolve a string with named, positional, printf, or plural arguments. */
    public function format(string $text, mixed $arg = null, array $args = [], array $args2 = [], ?string $locale = null): string
    {
        $number = is_numeric($arg) ? $arg + 0 : null;
        $arguments = $args2 !== [] && $number !== null && $number != 1
            ? $args2
            : ($args ?: (is_array($arg) ? $arg : ($arg === null ? [] : [$arg])));

        $named = $arguments !== [] && !array_is_list($arguments);
        $replace = $named ? $arguments : [];
        $line = $number !== null
            ? $this->choice($text, $number, $replace, $locale)
            : $this->get($text, $replace, $locale);

        if (is_array($line)) {
            $line = $this->choice($text, 1, $replace, $locale);
        }
        if ($named || $arguments === []) {
            return $line;
        }
        if (preg_match_all('/:\\w+/', $line, $matches)) {
            $pairs = [];
            foreach (array_values(array_unique($matches[0])) as $index => $placeholder) {
                if (array_key_exists($index, $arguments)) {
                    $pairs[$placeholder] = self::stringify($arguments[$index]);
                }
            }
            return strtr($line, $pairs);
        }
        if (!str_contains($line, '%')) {
            return $line;
        }
        try {
            return vsprintf($line, $arguments);
        } catch (\ValueError | \ArgumentCountError) {
            return $line;
        }
    }

    public function handleMissingKeysUsing(?callable $callback): static
    {
        $this->missingHandler = $callback === null ? null : Closure::fromCallable($callback);
        return $this;
    }

    // ------------------------------------------------------------------
    // Sources
    // ------------------------------------------------------------------

    /** Add a lang directory. $prepend = lowest priority (package defaults). */
    public function addPath(string $path, bool $prepend = false): static
    {
        $path = rtrim(dir_path($path), '/\\');
        $this->paths = array_values(array_unique($prepend ? [$path, ...$this->paths] : [...$this->paths, $path]));
        $this->loaded = [];
        return $this;
    }

    /** Add an extra flat file (returns an array) for a locale. */
    public function addLanguageFile(string $file, bool $prepend = false, ?string $locale = null): void
    {
        $locale ??= $this->locale;
        $this->validateLocale($locale);

        $file = dir_path($file);
        $files = $this->files[$locale] ?? [];

        $this->files[$locale] = array_values(array_unique($prepend ? [$file, ...$files] : [...$files, $file]));

        unset($this->loaded[$locale]['*']);
    }

    public function addLines(array $lines, ?string $locale = null, string $group = '*'): void
    {
        $locale ??= $this->locale;
        $this->validateLocale($locale);
        $this->validateGroup($group);

        $this->runtime[$locale][$group] = array_replace_recursive($this->runtime[$locale][$group] ?? [], $lines);

        unset($this->loaded[$locale][$group]);
    }

    public function all(?string $locale = null, string $group = '*'): array
    {
        $this->validateGroup($group);
        return $this->group($locale ?? $this->locale, $group);
    }

    /** Drop every cache and runtime line (use after deploys / in dev watchers of long-running workers). */
    public function flush(): void
    {
        $this->loaded = $this->runtime = $this->loadedFiles = [];
    }

    public function getLoadedFiles(): array
    {
        return array_keys($this->loadedFiles);
    }

    public function hasLoadedFiles(): bool
    {
        return $this->loadedFiles !== [];
    }

    // ------------------------------------------------------------------
    // Lookup
    // ------------------------------------------------------------------

    private function lookup(string $key, string $locale, bool $fallback, ?string &$resolvedLocale = null): string|array|null
    {
        $resolvedLocale = $locale;
        $line = $this->line($key, $locale);

        if ($line === null && $fallback) {
            foreach ($this->fallbackChain($locale) as $other) {
                if (($line = $this->line($key, $other)) !== null) {
                    $resolvedLocale = $other;
                    break;
                }
            }
        }

        return $line;
    }

    /** en_GB → [en, <configured fallback>] */
    private function fallbackChain(string $locale): array
    {
        $chain = [];
        $parent = $locale;
        while (preg_match('/^(.+)[-_][^-_]+$/D', $parent, $match)) {
            $chain[] = $parent = $match[1];
        }
        if ($this->fallback !== null) {
            $chain[] = $this->fallback;
            $parent = $this->fallback;
            while (preg_match('/^(.+)[-_][^-_]+$/D', $parent, $match)) {
                $chain[] = $parent = $match[1];
            }
        }
        return array_diff(array_unique($chain), [$locale]);
    }

    private function line(string $key, string $locale): string|array|null
    {
        $flat = $this->group($locale, '*');

        // 1. Exact key: free-text keys ("Welcome back, :name") and flat dotted keys.
        if (isset($flat[$key])) {
            return $this->assertLine($key, $flat[$key]);
        }

        $dot = strpos($key, '.');
        if ($dot === false) {
            return null;
        }

        // 2. Group file: auth.failed → lang/{locale}/auth.php → ['failed']
        $group = $this->group($locale, substr($key, 0, $dot));
        if ($group !== [] && ($line = Arr::get($group, substr($key, $dot + 1))) !== null) {
            return $this->assertLine($key, $line);
        }

        // 3. Legacy: nested arrays inside the flat file (drop once migrated to group files).
        return ($line = Arr::get($flat, $key)) === null ? null : $this->assertLine($key, $line);
    }

    private function assertLine(string $key, mixed $line): string|array
    {
        if (!is_string($line) && !is_array($line)) {
            throw new UnexpectedValueException("Translation [$key] must be a string or array.");
        }
        return $line;
    }

    // ------------------------------------------------------------------
    // Loading
    // ------------------------------------------------------------------

    private function group(string $locale, string $group): array
    {
        $this->validateLocale($locale);
        if (isset($this->loaded[$locale][$group])) {
            return $this->loaded[$locale][$group];
        }

        // Keys may come from free text; never let them reach the filesystem.
        if ($group !== '*' && !preg_match('/^[\w-]+$/D', $group)) {
            return [];
        }

        return $this->loaded[$locale][$group] = $this->read($locale, $group);
    }

    private function read(string $locale, string $group): array
    {
        $this->validateLocale($locale);

        $files = [];
        foreach ($this->paths as $dir) {
            $files[] = $group === '*' ? "$dir/$locale.php" : "$dir/$locale/$group.php";
        }
        if ($group === '*') {
            array_push($files, ...($this->files[$locale] ?? []));
        }

        $lines = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $data = $this->include($file);
            // A single source is used as-is: no merge, no copy.
            $lines = $lines === [] ? $data : array_replace_recursive($lines, $data);
        }

        if (isset($this->runtime[$locale][$group])) {
            $lines = array_replace_recursive($lines, $this->runtime[$locale][$group]);
        }

        return $lines;
    }

    private function include(string $file): array
    {
        if (array_key_exists($file, $this->loadedFiles)) {
            return $this->loadedFiles[$file];
        }
        $debug = is_debug_mode();
        $started = $debug ? hrtime(true) : 0;
        $memory = $debug ? memory_get_usage(true) : 0;

        // Static closure: the language file cannot see or clobber $this / local variables.
        $lines = (static fn() => require $file)();

        if (!is_array($lines)) {
            throw new UnexpectedValueException("Language file [$file] must return an array.");
        }

        $this->loadedFiles[$file] = $lines;

        if ($debug) {
            event('app:translator.loadedLanguageFile', [
                'file' => $file,
                'load_time' => round((hrtime(true) - $started) / 1e6, 6),
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

    private function validateGroup(string $group): void
    {
        if ($group !== '*' && !preg_match('/^[\w-]+$/D', $group)) {
            throw new InvalidArgumentException('Invalid translation group.');
        }
    }

    private function reportMissing(string $key, string $locale): void
    {
        if ($this->missingHandler === null || $this->reporting) {
            return;
        }
        $this->reporting = true;
        try {
            ($this->missingHandler)($key, $locale);
        } finally {
            $this->reporting = false;
        }
    }

    // ------------------------------------------------------------------
    // Replacements
    // ------------------------------------------------------------------

    /** :name  :NAME  :Name — longest placeholder wins, replaced values are never re-processed. */
    private function replace(string|array $line, array $replace): string|array
    {
        if ($replace === []) {
            return $line;
        }

        if (is_array($line)) {
            foreach ($line as $k => $value) {
                if (is_string($value) || is_array($value)) {
                    $line[$k] = $this->replace($value, $replace);
                }
            }
            return $line;
        }

        if (!str_contains($line, ':')) {
            return $line;
        }

        $pairs = [];
        foreach ($replace as $key => $value) {
            $key = (string) $key;
            $text = self::stringify($value);
            $pairs[":$key"] = $text;

            if ($key !== strtolower($key)) {
                continue;
            }

            // Case variants are built only when the line actually uses them.
            $upper = ':' . strtoupper($key);
            if ($upper !== ":$key" && str_contains($line, $upper)) {
                $pairs[$upper] = function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
            }

            $first = ':' . ucfirst($key);
            if ($first !== ":$key" && str_contains($line, $first)) {
                $pairs[$first] = function_exists('mb_strtoupper') && function_exists('mb_substr')
                    ? mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8')
                    : ucfirst($text);
            }
        }

        return strtr($line, $pairs);
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException('Replacement values must be scalar, null or Stringable.'),
        };
    }

    // ------------------------------------------------------------------
    // Pluralization
    // ------------------------------------------------------------------

    /**
     * Supports "{0} none|[1,5] a few|[6,*] many", positional forms ("one|other")
     * and arrays keyed by CLDR category (['one' => ..., 'few' => ..., 'other' => ...]).
     */
    private function select(string|array $line, int|float $number, string $locale): string
    {
        $categories = PluralRules::categories($locale);
        $category = PluralRules::category($locale, $number);
        $index = (int) array_search($category, $categories, true);

        if (is_array($line)) {
            $selected = $line[$category] ?? $line[$index] ?? $line['other'] ?? ($line === [] ? '' : end($line));
            if (!is_string($selected)) {
                throw new UnexpectedValueException('Plural forms must be strings.');
            }
            return $selected;
        }

        $forms = [];
        foreach (explode('|', $line) as $part) {
            if (preg_match('/^\s*\{(-?\d+(?:\.\d+)?)\}\s*(.*)$/s', $part, $m)) {
                if ($number == (float) $m[1]) {
                    return $m[2];
                }
            } elseif (preg_match('/^\s*\[(-?\d+(?:\.\d+)?|\*),\s*(-?\d+(?:\.\d+)?|\*)\]\s*(.*)$/s', $part, $m)) {
                if (($m[1] === '*' || $number >= (float) $m[1]) && ($m[2] === '*' || $number <= (float) $m[2])) {
                    return $m[3];
                }
            } else {
                $forms[] = $part;
            }
        }

        return $forms[$index] ?? ($forms === [] ? $line : end($forms));
    }
}
