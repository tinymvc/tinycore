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
 * @method static BaseCache setName(string $name, ?string $cacheDir = null)
 * @method static BaseCache setCachePath(string $path)
 * @method static BaseCache name(string $name, ?string $cacheDir = null)
 * @method static BaseCache reload()
 * @method static BaseCache store(string $key, mixed $data, ?string $expire = null)
 * @method static BaseCache remember(string $key, mixed $data, ?string $expire = null)
 * @method static bool has(string $key, bool $eraseExpired = false)
 * @method static mixed load(string $key, callable $callback, ?string $expire = null)
 * @method static mixed retrieve(string|array $keys, bool $eraseExpired = false)
 * @method static array retrieveAll(bool $eraseExpired = false)
 * @method static mixed get(string $key, bool $eraseExpired = false)
 * @method static mixed metadata(string $key)
 * @method static array getExpired()
 * @method static BaseCache erase(string|array $keys)
 * @method static BaseCache eraseExpired()
 * @method static BaseCache delete(string|array $keys)
 * @method static BaseCache flush()
 * @method static BaseCache flushIf(bool $condition)
 * @method static BaseCache clear()
 * @method static void unload()
 * @method static void saveChanges()
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
