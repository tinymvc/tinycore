<?php

namespace Spark\Cache;

use Spark\Foundation\Application;
use Spark\Utils\RedisConnector;
use function array_key_exists;
use function func_num_args;
use function in_array;
use function is_array;

/** Resolves application-scoped repositories; purging never deletes stored entries. */
class CacheManager
{
    /** @var array<string, array<string, Cache>> */
    private array $stores = [];

    /** @var array<string, array> Store configuration remains stable until purge. */
    private array $configurations = [];

    public function __construct(private readonly Application $app)
    {
    }

    public function store(?string $name = null): Cache
    {
        if (func_num_args() > 1) {
            throw new \InvalidArgumentException('Cache::store() selects a configured store; use put() to write values.');
        }
        return $this->repository($name, 'default');
    }

    /** @internal Resolve one namespace in a configured store. */
    public function repository(?string $name, string $namespace): Cache
    {
        $name ??= $this->getDefaultStore();

        if (isset($this->stores[$name][$namespace])) {
            return $this->stores[$name][$namespace];
        }

        $config = $this->configurations[$name]
            ?? self::resolveConfig((array) $this->app->getConfig('cache', []), $name);
        $repository = new Cache($namespace, $config, $this, $name);

        $this->configurations[$name] = $config;
        return $this->stores[$name][$namespace] = $repository;
    }

    /** @internal Whether a repository still belongs to this store's current lifecycle. */
    public function ownsRepository(string $store, string $namespace, Cache $repository): bool
    {
        return ($this->stores[$store][$namespace] ?? null) === $repository;
    }

    public function driver(?string $name = null): Cache
    {
        return $this->store($name);
    }

    /** Resolve a namespace within the default store (the existing TinyCore API). */
    public function make(string $name = 'default'): Cache
    {
        return $this->repository(null, $name);
    }

    public function getDefaultStore(): string
    {
        return self::defaultName((array) $this->app->getConfig('cache', []));
    }

    public function purge(?string $name = null): void
    {
        $name ??= $this->getDefaultStore();
        unset($this->stores[$name], $this->configurations[$name]);
    }

    public function forgetDriver(array|string|null $name = null): static
    {
        foreach ((array) ($name ?? $this->getDefaultStore()) as $store) {
            $this->purge($store);
        }
        return $this;
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->store()->$method(...$arguments);
    }

    /** @internal Both configuration spellings are supported during migration. */
    public static function defaultName(array $config): string
    {
        return (string) (($config['default'] ?? null) ?: ($config['store'] ?? null) ?: ($config['driver'] ?? null) ?: 'database');
    }

    /** @internal Resolve a configured store, retaining built-in default driver shorthand. */
    public static function resolveConfig(array $config, ?string $name = null): array
    {
        $name ??= self::defaultName($config);
        $stores = $config['stores'] ?? $config['connections'] ?? [];
        if (!is_array($stores)) {
            throw new \InvalidArgumentException('Cache stores must be an array.');
        }
        $connection = $stores[$name] ?? null;
        if (!is_array($connection)) {
            if (
                array_key_exists($name, $stores) || $name !== self::defaultName($config)
                || !in_array(strtolower($name), ['database', 'file', 'redis'], true)
            ) {
                throw new \InvalidArgumentException("Cache store [$name] is not defined.");
            }
            $connection = [];
        }
        return RedisConnector::mergeConfig(['driver' => $name], $connection);
    }
}
