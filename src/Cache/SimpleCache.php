<?php

namespace Spark\Cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use Spark\Cache\Contracts\CacheContract;
use Spark\Cache\Exceptions\CacheInvalidArgumentException;
use function is_string;

/** PSR-16 adapter; the native cache keeps its fluent API and unrestricted keys. */
class SimpleCache implements CacheInterface
{
    public function __construct(private CacheContract $cache)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->retrieve($this->validateKey($key)) ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $this->cache->store($key, $value, $ttl);

        return true;
    }

    public function delete(string $key): bool
    {
        $this->cache->erase($this->validateKey($key));

        return true;
    }

    public function clear(): bool
    {
        $this->cache->flush();

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$this->validateKey($key)] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            // PHP converts numeric string array keys to integers.
            $this->set($this->validateKey(is_int($key) ? (string) $key : $key), $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($this->validateKey($key));
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->validateKey($key));
    }

    private function validateKey(mixed $key): string
    {
        if (!is_string($key) || $key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            throw new CacheInvalidArgumentException('Cache keys must be non-empty strings without {}()/\\@: characters.');
        }

        return $key;
    }
}
