<?php

namespace Spark;

use function is_float;
use function is_int;
use function strlen;

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
     * Load environment variables. Uses the compiled cache if fresh,
     * otherwise parses the .env file, caches it, and populates the environment.
     */
    public function load(): array
    {
        if (!file_exists($this->envPath)) {
            return [];
        }

        $env = $this->isFresh() ? $this->loadCompiled() : $this->compile();

        envs($env);

        return $env;
    }

    /**
     * Parse the .env file and compile it to the cache path.
     */
    public function compile(): array
    {
        $env = $this->parse();

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

        return filemtime($this->compilePath) >= filemtime($this->envPath);
    }

    /**
     * Load the compiled environment array from the cache file.
     */
    protected function loadCompiled(): array
    {
        return require $this->compilePath;
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
        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and lines without '='
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
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
                $value = $this->stripInlineComment($value);

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
        $pos = strpos($value, ' #');

        return $pos !== false ? trim(substr($value, 0, $pos)) : $value;
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

            if (isset($_ENV[$var])) {
                return $_ENV[$var];
            }

            if (isset($_SERVER[$var])) {
                return $_SERVER[$var];
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