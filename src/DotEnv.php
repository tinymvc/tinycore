<?php

namespace Spark;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use function array_key_exists;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function is_dir;
use function is_file;
use function dirname;
use function filemtime;
use function var_export;
use function rtrim;
use function strlen;
use function ltrim;

/**
 * A simple .env file loader with caching and support for nested variable interpolation.
 *
 * Usage:
 *   DotEnv::loadFrom(__DIR__ . '/.env', __DIR__ . '/.env.cache.php');
 *
 * This will load environment variables from the specified .env file, using a compiled cache
 * for faster loading on subsequent runs. It supports comments, quoted values, inline comments,
 * and nested variable references like ${VAR}.
 */
class DotEnv
{
    /**
     * Bootstrap environment variables for a framework root directory.
     *
     * @param string $basePath
     * @param string $envFile
     * @param string $cacheFile
     * @return array<string, mixed>
     */
    public static function bootstrap(string $basePath, string $envFile = '.env', string $cacheFile = 'bootstrap/cache/env.php'): array
    {
        return self::loadFrom(
            envPath: dir_path(rtrim($basePath, '/\\') . '/' . ltrim($envFile, '/\\')),
            compilePath: dir_path(rtrim($basePath, '/\\') . '/' . ltrim($cacheFile, '/\\'))
        );
    }

    /**
     * Discover configuration files and cache merged config.
     *
     * @param string $folder Path to configuration directory.
     * @param string $cache Path to cached config file.
     * @return array<string, mixed>
     */
    public static function discoverConfig(string $folder, string $cache): array
    {
        $folder = dir_path($folder);
        $cache = dir_path($cache);

        if (self::isConfigCacheFresh($folder, $cache)) {
            $cached = self::loadConfigCache($cache);

            if (is_array($cached)) {
                return $cached;
            }
        }

        if (!is_dir($folder)) {
            if (is_file($cache)) {
                unlink($cache);
            }

            return [];
        }

        $config = [];
        $files = self::collectConfigFiles($folder);

        foreach ($files as $path => $mtime) {
            $relativePath = substr($path, strlen($folder) + 1);
            $key = str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['.', ''],
                $relativePath
            );

            $value = require $path;

            if (is_array($value)) {
                data_set($config, ltrim($key, '.'), $value);
            }
        }

        self::writeConfigCache($cache, $config, $files);

        return $config;
    }

    /**
     * Collect file mtimes for config php files to detect source changes.
     *
     * @param string $folder
     * @return array<string, int>
     */
    protected static function collectConfigFiles(string $folder): array
    {
        if (!is_dir($folder)) {
            return [];
        }

        $folder = rtrim($folder, '/\\');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $mtime = $file->getMTime();
            if ($mtime === false) {
                continue;
            }

            $files[$file->getPathname()] = $mtime;
        }

        return $files;
    }

    /**
     * Check whether config cache is fresh based on source file mtimes.
     */
    protected static function isConfigCacheFresh(string $folder, string $cache): bool
    {
        if (!is_file($cache) || !is_dir($folder)) {
            return false;
        }

        $compiled = self::loadConfigCachePayload($cache);

        if (!is_array($compiled) || !isset($compiled['files']) || !is_array($compiled['files'])) {
            return false;
        }

        $compiledFiles = $compiled['files'];
        $files = self::collectConfigFiles($folder);
        $cacheModifiedAt = filemtime($cache);

        if (count($compiledFiles) !== count($files) || $cacheModifiedAt === false) {
            return false;
        }

        $compiledPaths = array_keys($compiledFiles);
        $currentPaths = array_keys($files);

        sort($compiledPaths);
        sort($currentPaths);

        if ($compiledPaths !== $currentPaths) {
            return false;
        }

        foreach ($files as $path => $mtime) {
            if (!array_key_exists($path, $compiledFiles)) {
                return false;
            }

            $compiledMtime = $compiledFiles[$path];

            if (
                !is_int($compiledMtime) && !(
                    is_string($compiledMtime) && preg_match('/^\d+$/', $compiledMtime) === 1
                )
            ) {
                return false;
            }

            $compiledMtime = (int) $compiledMtime;

            if ($compiledMtime !== $mtime || $mtime > $cacheModifiedAt) {
                return false;
            }
        }

        return true;
    }

    /**
     * Load compiled config cache.
     */
    protected static function loadConfigCache(string $cache): ?array
    {
        $compiled = self::loadConfigCachePayload($cache);

        if (!is_array($compiled)) {
            return null;
        }

        if (isset($compiled['config']) && is_array($compiled['config'])) {
            return $compiled['config'];
        }

        return $compiled;
    }

    /**
     * Load the raw compiled config cache payload.
     */
    protected static function loadConfigCachePayload(string $cache): ?array
    {
        if (!is_file($cache)) {
            return null;
        }

        $config = require $cache;
        return is_array($config) ? $config : null;
    }

    /**
     * Write compiled config cache to disk.
     */
    protected static function writeConfigCache(string $cache, array $config, array $files = []): void
    {
        $cacheDir = dirname($cache);
        $payload = [
            'config' => $config,
            'files' => $files,
            'generated_at' => time(),
        ];

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return;
        }

        file_put_contents($cache, '<?php return ' . var_export($payload, true) . ';' . PHP_EOL);
    }

    /**
     * @param string $envPath Path to the .env file
     * @param string $compilePath Path to the compiled cache file
     */
    public function __construct(
        protected string $envPath,
        protected string $compilePath,
    ) {
    }

    /**
     * Static helper to load environment variables from a .env file with caching.
     */
    public static function loadFrom(string $envPath, string $compilePath): array
    {
        $env = new self($envPath, $compilePath);
        return $env->load();
    }

    /**
     * Parse a raw environment value using Laravel-like casting rules.
     */
    public static function parseValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);
        $lower = strtolower($value);

        return match ($lower) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => self::parseNumericValue($value),
        };
    }

    /**
     * Parse numeric-like environment values.
     */
    public static function parseNumericValue(string $value): mixed
    {
        $valueLength = strlen($value);

        if (preg_match('/^[+-]?\d+$/', $value)) {
            return (int) $value;
        }

        if (
            preg_match('/^[+-]?(?:\d+\.\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $value)
            || preg_match('/^[+-]?\d+[eE][+-]?\d+$/', $value)
        ) {
            return (float) $value;
        }

        if (
            $valueLength >= 2
            && (
                ($value[0] === '"' && $value[$valueLength - 1] === '"')
                || ($value[0] === "'" && $value[$valueLength - 1] === "'")
            )
        ) {
            $quote = $value[0];
            $value = substr($value, 1, -1);

            if ($quote === '"') {
                $value = stripcslashes($value);
            }

            return $value;
        }

        return $value;
    }

    /**
     * Convert a typed value to a safe string representation for putenv().
     */
    public static function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? '' : $encoded;
        }

        return (string) $value;
    }

    /**
     * Load environment variables. Uses the compiled cache if fresh,
     * otherwise parses the .env file, caches it, and populates the environment.
     */
    public function load(): array
    {
        if (!file_exists($this->envPath)) {
            $env = file_exists($this->compilePath) ? $this->loadCompiled() : [];
            if (is_array($env)) {
                envs($env);
                return $env;
            }

            return [];
        }

        $env = $this->isFresh()
            ? $this->loadCompiled()
            : $this->compile();

        if (!is_array($env)) {
            return [];
        }

        envs($env);

        return $env;
    }

    /**
     * Parse the .env file and compile it to the cache path.
     */
    public function compile(): array
    {
        $env = $this->parse();
        $cacheDir = dirname($this->compilePath);

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return $env;
        }

        file_put_contents(
            $this->compilePath,
            '<?php return ' . $this->export($env) . ';' . PHP_EOL
        );

        return $env;
    }

    /**
     * Check whether the compiled file is still fresh (exists and newer than the .env file).
     */
    public function isFresh(): bool
    {
        if (!file_exists($this->compilePath)) {
            return false;
        }

        if (!file_exists($this->envPath)) {
            return false;
        }

        return filemtime($this->compilePath) >= filemtime($this->envPath);
    }

    /**
     * Load the compiled environment array from the cache file.
     */
    protected function loadCompiled(): array
    {
        $compiled = require $this->compilePath;
        return is_array($compiled) ? $compiled : [];
    }

    /**
     * Parse the .env file into an associative array with properly typed values.
     *
     * Supports:
     *  - comments (#)
     *  - empty lines
     *  - quoted values (single & double) — always treated as strings
     *  - inline comments (outside quotes)
     *  - nested variable interpolation: ${VAR}
     *  - native type casting: true/false → bool, null → null, integers, floats
     */
    protected function parse(): array
    {
        $lines = file(
            $this->envPath,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        ) ?: [];
        $env = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and lines without '='
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Allow optional export prefix: export KEY=VALUE
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            if (str_starts_with($line, '#') || $line === '') {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $value = trim($value);

            // Quoted values are always kept as strings (no type casting)
            if ($this->isQuoted($value)) {
                $quote = $value[0];
                $value = substr($value, 1, -1);

                // Allow escape sequences only in double-quoted values
                if ($quote === '"') {
                    $value = str_replace(
                        ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                        ["\n", "\r", "\t", '"', '\\'],
                        $value,
                    );
                }

                // Resolve ${VAR} inside quoted strings, but keep as string
                $value = $this->resolveNestedVariables($value, $env);

            } else {
                // Remove inline comments (not inside quotes)
                $value = trim($this->stripInlineComment($value));

                // Resolve ${VAR} references before casting
                $value = $this->resolveNestedVariables($value, $env);

                // Cast to the appropriate native PHP type
                $value = $this->castValue($value);
            }

            $env[$key] = $value;
        }

        return $env;
    }

    /**
     * Cast an unquoted string value to its appropriate native PHP type.
     *
     * Rules (in order):
     *  - "true"          → (bool) true
     *  - "false"         → (bool) false
     *  - "null"          → null
     *  - pure integer literal     → (int)
     *  - pure float literal       → (float)
     *  - everything else          → (string)
     */
    protected function castValue(string $value): mixed
    {
        $lower = strtolower($value);

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if ($lower === 'null') {
            return null;
        }

        // Integer: optional leading sign, digits only
        if (preg_match('/^[+-]?\d+$/', $value)) {
            return (int) $value;
        }

        // Float: optional leading sign, digits with a single decimal point or exponent
        if (
            preg_match('/^[+-]?(\d+\.\d*|\.\d+)([eE][+-]?\d+)?$/', $value)
            || preg_match('/^[+-]?\d+[eE][+-]?\d+$/', $value)
        ) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * Export the parsed environment array as a valid PHP literal,
     * preserving native types (bool, null, int, float, string).
     *
     * Unlike var_export(), this produces a compact, human-readable format.
     */
    protected function export(array $env): string
    {
        $lines = [];

        foreach ($env as $key => $value) {
            $lines[] = '  ' . var_export($key, true) . ' => ' . $this->exportScalar($value);
        }

        return "array(\n" . implode(",\n", $lines) . ",\n)";
    }

    /**
     * Render a single scalar value as a PHP literal.
     */
    protected function exportScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Ensure the literal is always a valid PHP float (has decimal point or exponent)
            $str = var_export($value, true);
            return $str;
        }

        // String — use var_export for safe escaping
        return var_export($value, true);
    }

    /**
     * Determine if a value is wrapped in matching quotes.
     */
    protected function isQuoted(string $value): bool
    {
        if (strlen($value) < 2) {
            return false;
        }

        $first = $value[0];
        $last = $value[-1];

        return ($first === '"' && $last === '"')
            || ($first === "'" && $last === "'");
    }

    /**
     * Strip an inline comment from an unquoted value.
     */
    protected function stripInlineComment(string $value): string
    {
        $parts = preg_split('/\s#/', $value, 2);

        return $parts[0] ?? '';
    }

    /**
     * Resolve ${VAR} placeholders using already-parsed values
     * and falling back to existing environment variables.
     */
    protected function resolveNestedVariables(string $value, array $env): string
    {
        return preg_replace_callback('/\$\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $matches) use ($env) {
            $var = $matches[1];

            if (array_key_exists($var, $env)) {
                return $this->scalarToString($env[$var]);
            }

            if (array_key_exists($var, $_ENV)) {
                return $this->scalarToString($_ENV[$var]);
            }

            if (array_key_exists($var, $_SERVER)) {
                return $this->scalarToString($_SERVER[$var]);
            }

            $envVal = getenv($var);

            return $envVal !== false ? $envVal : $matches[0];
        }, $value);
    }

    /**
     * Convert a typed scalar back to its string representation for interpolation.
     */
    protected function scalarToString(mixed $value): string
    {
        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
