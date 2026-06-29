<?php

namespace Spark\Utils;

use RuntimeException;
use function defined;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Reusable Redis connector for cache, lock, and queue backends.
 *
 * This helper centralizes phpredis connection setup so Cache, Lock, and Queue
 * share a single consistent Redis driver implementation.
 */
final class RedisConnector
{
    private const string DEFAULT_HOST = '127.0.0.1';
    private const int DEFAULT_PORT = 6379;
    private const float DEFAULT_TIMEOUT = 2.5;
    private const float DEFAULT_READ_TIMEOUT = 2.5;
    private const int DEFAULT_RETRY_INTERVAL = 0;
    private const int DEFAULT_DATABASE = 0;

    /** @var array<string, \Redis> */
    private static array $instances = [];

    /** @var array<string, array<string, mixed>> */
    private static array $instanceMeta = [];

    /**
     * Create or reuse a Redis connection.
     */
    public static function make(array $config, string $connectionName = 'default'): \Redis
    {
        $config = self::resolveConnectionConfig($config);

        $cacheKey = self::cacheKey($connectionName, $config);

        if (!isset(self::$instances[$cacheKey])) {
            self::$instances[$cacheKey] = self::connect($config, $connectionName);
            self::$instanceMeta[$cacheKey] = self::connectionMeta($config);
        }

        return self::$instances[$cacheKey];
    }

    /**
     * Resolve connection config from framework cache/queue/lock configuration shapes.
     */
    public static function resolveConnectionConfig(array $raw): array
    {
        $config = self::mergeConfig(self::defaults(), $raw);

        if (!empty($raw['url']) && is_string($raw['url'])) {
            $config = self::mergeConfig($config, self::parseUrl($raw['url']));
        }

        return self::normalizeConnection($config);
    }

    /**
     * Merge two arrays where override values replace base values.
     *
     * Null values are ignored and `options` are merged by dedicated logic in
     * normalizeConnection().
     */
    public static function mergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($key === 'options') {
                if (!is_array($value)) {
                    continue;
                }

                $baseOptions = is_array($base['options'] ?? null) ? $base['options'] : [];
                $base['options'] = [...$baseOptions, ...$value];
                continue;
            }

            if ($value === null) {
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Return normalized driver meta for monitoring/debugging.
     */
    public static function getConnectionMeta(array $config): array
    {
        return self::connectionMeta(self::normalizeConnection($config));
    }

    private static function defaults(): array
    {
        return [
            'host' => self::DEFAULT_HOST,
            'port' => self::DEFAULT_PORT,
            'timeout' => self::DEFAULT_TIMEOUT,
            'read_timeout' => self::DEFAULT_READ_TIMEOUT,
            'retry_interval' => self::DEFAULT_RETRY_INTERVAL,
            'persistent' => false,
            'persistent_id' => null,
            'database' => self::DEFAULT_DATABASE,
            'username' => null,
            'password' => null,
            'prefix' => '',
            'path' => null,
            'socket' => null,
            'url' => null,
            'options' => [],
        ];
    }

    private static function normalizeConnection(array $config): array
    {
        if (!is_array($config['options'] ?? null)) {
            $config['options'] = [];
        }

        if (!empty($config['path']) && empty($config['socket'])) {
            $config['socket'] = (string) $config['path'];
        }

        if (!empty($config['socket']) && is_string($config['socket'])) {
            $config['socket'] = trim($config['socket']);
            if ($config['socket'] === '') {
                $config['socket'] = null;
            }
        }

        $config['host'] = (string) $config['host'];
        $config['port'] = (int) $config['port'];
        $config['timeout'] = (float) $config['timeout'];
        $config['read_timeout'] = (float) $config['read_timeout'];
        $config['retry_interval'] = (int) $config['retry_interval'];
        $config['database'] = (int) $config['database'];
        $config['persistent'] = (bool) $config['persistent'];
        $config['prefix'] = is_string($config['prefix']) ? trim($config['prefix']) : '';

        if ($config['prefix'] !== '' && str_ends_with($config['prefix'], ':')) {
            $config['prefix'] = rtrim($config['prefix'], ':');
        }

        $config['options'] = self::normalizeOptions($config['options']);

        return $config;
    }

    /**
     * Build a cache key for shared connection reuse.
     */
    private static function cacheKey(string $connectionName, array $config): string
    {
        $cacheKeyParts = $config;
        unset($cacheKeyParts['password']);

        if (isset($cacheKeyParts['options']) && is_array($cacheKeyParts['options'])) {
            $cacheKeyParts['options'] = self::sortOptionKeys($cacheKeyParts['options']);
        }

        ksort($cacheKeyParts);

        return sprintf('%s:%s', $connectionName, md5(json_encode($cacheKeyParts, JSON_UNESCAPED_UNICODE)));
    }

    /**
     * Parse a Redis DSN.
     *
     * Supported examples:
     * - redis://[:password]@127.0.0.1:6379/1
     * - redis://user:password@127.0.0.1:6379?timeout=3&read_timeout=5
     * - unix:///var/run/redis.sock?database=1
     */
    private static function parseUrl(string $url): array
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return [];
        }

        $config = [];

        if (($parsed['scheme'] ?? '') === 'unix') {
            $config['socket'] = $parsed['path'] ?? null;
        } else {
            if (isset($parsed['host']) && $parsed['host'] !== '') {
                $config['host'] = $parsed['host'];
            }

            if (isset($parsed['port'])) {
                $config['port'] = (int) $parsed['port'];
            }
        }

        if (isset($parsed['user'])) {
            $config['username'] = rawurldecode((string) $parsed['user']);
        }

        if (isset($parsed['pass'])) {
            $config['password'] = rawurldecode((string) $parsed['pass']);
        }

        if (isset($parsed['path']) && is_string($parsed['path'])) {
            if (($parsed['scheme'] ?? '') !== 'unix') {
                $dbPath = trim($parsed['path'], '/');
                if ($dbPath !== '' && ctype_digit($dbPath)) {
                    $config['database'] = (int) $dbPath;
                }
            }
        }

        if (isset($parsed['query']) && is_string($parsed['query'])) {
            parse_str($parsed['query'], $query);

            foreach ($query as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $normalizedKey = strtolower($key);
                $normalizedValue = is_string($value) ? rawurldecode($value) : $value;

                match ($normalizedKey) {
                    'host' => $config['host'] = (string) $normalizedValue,
                    'port' => $config['port'] = (int) $normalizedValue,
                    'timeout' => $config['timeout'] = (float) $normalizedValue,
                    'read_timeout' => $config['read_timeout'] = (float) $normalizedValue,
                    'retry_interval' => $config['retry_interval'] = (int) $normalizedValue,
                    'database', 'db' => $config['database'] = (int) $normalizedValue,
                    'username' => $config['username'] = (string) $normalizedValue,
                    'password' => $config['password'] = (string) $normalizedValue,
                    'prefix' => $config['prefix'] = (string) $normalizedValue,
                    'socket', 'path' => $config['socket'] = (string) $normalizedValue,
                    default => null,
                };
            }
        }

        return self::normalizeConnection(self::mergeConfig(self::defaults(), $config));
    }

    private static function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $normalized[$key] = $value;
                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            $constantName = 'Redis::OPT_' . strtoupper($key);
            if (!defined($constantName)) {
                continue;
            }

            $normalized[constant($constantName)] = $value;
        }

        return $normalized;
    }

    private static function sortOptionKeys(array $options): array
    {
        ksort($options);
        return $options;
    }

    private static function connectionMeta(array $config): array
    {
        return [
            'host' => (string) $config['host'],
            'port' => (int) $config['port'],
            'socket' => $config['socket'],
            'database' => (int) $config['database'],
            'timeout' => (float) $config['timeout'],
            'read_timeout' => (float) $config['read_timeout'],
            'retry_interval' => (int) $config['retry_interval'],
            'persistent' => (bool) $config['persistent'],
            'prefix' => (string) $config['prefix'],
        ];
    }

    private static function connectionLabel(array $config): string
    {
        if (!empty($config['socket'])) {
            return (string) $config['socket'];
        }

        return sprintf('%s:%s', $config['host'], $config['port']);
    }

    private static function connect(array $config, string $connectionName): \Redis
    {
        if (!class_exists('\Redis')) {
            throw new RuntimeException('The PHP Redis extension (ext-redis) is required for redis driver.');
        }

        $host = (string) $config['host'];
        $port = (int) $config['port'];
        $timeout = (float) $config['timeout'];
        $readTimeout = (float) $config['read_timeout'];
        $retryInterval = (int) $config['retry_interval'];
        $persistent = (bool) $config['persistent'];
        $persistentId = (string) ($config['persistent_id'] ?? $connectionName);
        $database = (int) $config['database'];
        $prefix = (string) ($config['prefix'] ?? '');
        $socket = $config['socket'];
        $username = $config['username'];
        $password = $config['password'];

        $redis = new \Redis();
        if (!empty($socket) && is_string($socket)) {
            $connected = $persistent
                ? $redis->pconnect($socket, 0, $timeout, $persistentId, $retryInterval, $readTimeout)
                : $redis->connect($socket, 0, $timeout, null, $retryInterval, $readTimeout);
        } else {
            $connected = $persistent
                ? $redis->pconnect($host, $port, $timeout, $persistentId, $retryInterval, $readTimeout)
                : $redis->connect($host, $port, $timeout, null, $retryInterval, $readTimeout);
        }

        if (!$connected) {
            throw new RuntimeException(sprintf('Failed to connect to Redis: %s', self::connectionLabel($config)));
        }

        if ($username !== null && $username !== '' && $password !== null && $password !== '') {
            $redis->auth([$username, $password]);
        } elseif ($password !== null && $password !== '') {
            $redis->auth((string) $password);
        }

        if ($database > 0) {
            $redis->select($database);
        }

        if ($prefix !== '') {
            $redis->setOption(\Redis::OPT_PREFIX, "$prefix:");
        }

        foreach ((array) $config['options'] as $option => $value) {
            $redis->setOption((int) $option, $value);
        }

        if (defined('\Redis::OPT_SERIALIZER') && defined('\Redis::SERIALIZER_NONE')) {
            $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
        }

        return $redis;
    }
}
