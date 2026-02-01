<?php

namespace Spark\Facades;

use Spark\Utils\Cache as BaseCache;

/**
 * Facade Cache
 * 
 * This class serves as a facade for the Cache system, providing a static interface to the underlying Cache class.
 * It allows easy access to caching methods such as storing, retrieving, and deleting cache items
 * without needing to instantiate the Cache class directly.
 * 
 * @method static BaseCache store(string $key, mixed $data, ?string $expire = null)
 * @method static bool has(string $key, bool $eraseExpired = false)
 * @method static mixed load(string $key, callable $callback, ?string $expire = null)
 * @method static mixed retrieve(string|array $keys, bool $eraseExpired = false)
 * @method static array retrieveAll(bool $eraseExpired = false)
 * @method static mixed metadata(string $key)
 * @method static array getExpired()
 * @method static BaseCache erase(string|array $keys)
 * @method static BaseCache eraseExpired()
 * @method static BaseCache flush()
 * @method static BaseCache flushIf(bool $condition)
 * @method static BaseCache clear()
 * @method static BaseCache storeMany(array $items, null|string $expire = null)
 * @method static BaseCache storeManyWithExpiry(array $items)
 * @method static int|false increment(string $key, int $amount = 1)
 * @method static int|false decrement(string $key, int $amount = 1)
 * @method static bool add(string $key, mixed $value, null|string $expire = null)
 * @method static mixed remember(string $key, callable $callback)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static array stats()
 * @method static null|int ttl(string $key)
 * @method static BaseCache optimize()
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Cache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseCache::class;
    }
}
