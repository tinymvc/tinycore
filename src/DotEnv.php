<?php

namespace Spark;

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
            '<?php return ' . var_export($env, true) . ';' . PHP_EOL
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
     * Parse the .env file into an associative array.
     *
     * Supports:
     *  - comments (#)
     *  - empty lines
     *  - quoted values (single & double)
     *  - inline comments (outside quotes)
     *  - nested variable interpolation: ${VAR}
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

            // Strip surrounding quotes
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
            } else {
                // Remove inline comments (not inside quotes)
                $value = $this->stripInlineComment($value);
            }

            // Resolve nested ${VAR} references
            $value = $this->resolveNestedVariables($value, $env);

            $env[$key] = $value;
        }

        return $env;
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

            return $env[$var] ?? $_ENV[$var] ?? $_SERVER[$var] ?? getenv($var) ?: $matches[0];
        }, $value);
    }
}