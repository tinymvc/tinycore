<?php

namespace Spark\Utils;

use Spark\Contracts\Utils\CacheUtilContract;
use Spark\Exceptions\Utils\FailedToSaveCacheFileException;
use Spark\Support\Traits\Macroable;

/**
 * Class Cache
 * 
 * Cache class for managing temporary file-based cache storage.
 * Stores serialized data as JSON in the filesystem for fast data retrieval.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Cache implements CacheUtilContract
{
    use Macroable;

    /** @var string Path to the cache file */
    private string $cachePath;

    /** @var array Holds cached data in memory */
    private array $cacheData;

    /** @var bool Indicates if expired data has been erased */
    private bool $erased;

    /** @var bool Indicates if cache has been loaded from the filesystem */
    private bool $cached;

    /** @var bool Tracks changes in cache data for saving on destruction */
    private bool $changed;

    /** @var string The Name of the cache file */
    private string $name;

    /**
     * Construct a new cache object.
     *
     * @param string $name The name of the cache.
     * @param null|string $cacheDir The Directory path to store this cache file.
     */
    public function __construct(string $name = 'default', ?string $cacheDir = null)
    {
        $this->setName($name, $cacheDir);
    }

    /**
     * Sets the name of the cache.
     *
     * The cache name is used to build the filename for the cache storage.
     * The filename is built by concatenating the md5 of the name with '.cache'
     * and adding it to the tmp_dir path.
     *
     * @param string $name The cache name.
     * @param string|null $cacheDir The path to the cache directory.
     * @return self The instance of the cache for method chaining.
     */
    public function setName(string $name, ?string $cacheDir = null): self
    {
        $cacheDir ??= config('cache_dir');

        $this->name = $name;
        $this->cachePath = dir_path($cacheDir . '/' . md5($name) . '.cache');
        $this->cacheData = [];
        $this->erased = false;
        $this->cached = false;
        $this->changed = false;

        return $this;
    }

    /**
     * Sets the path of the cache file.
     *
     * The set cache path is used to store the cache data.
     * If the cache path is not set, the cache name is used to build the filename.
     *
     * @param string $path The path of the cache file.
     * @return self The instance of the cache for method chaining.
     */
    public function setCachePath(string $path): self
    {
        $this->cachePath = dir_path($path);
        return $this;
    }

    /**
     * Reloads the cache data from the file if it hasn't been loaded yet.
     *
     * @return self
     */
    public function reload(): self
    {
        if (!$this->cached) {
            // Cache is loaded, avoid moutiple loads.
            $this->cached = true;

            // Retrieve all cached entries for this object.
            $this->cacheData = file_exists($this->cachePath)
                ? json_decode(file_get_contents($this->cachePath), true)
                : [];
        }

        return $this;
    }

    /**
     * Unloads the cache by resetting all cache-related properties.
     * 
     * Calls the destructor to handle any cleanup, and then sets the cache status
     * indicators to false and clears the in-memory cache data.
     */
    public function unload(): void
    {
        $this->__destruct();
        $this->cached = false;
        $this->changed = false;
        $this->erased = false;
        $this->cacheData = [];
    }

    /**
     * Checks if a cache key exists and optionally erases expired entries.
     *
     * @param string $key The key to check in cache.
     * @param bool $eraseExpired Whether to erase expired entries before checking.
     * @return bool
     */
    public function has(string $key, bool $eraseExpired = false): bool
    {
        // Reload cache data if not loaded.
        $this->reload();

        // Check if cache is already exists, else store it into cache. 
        if ($eraseExpired) {
            $this->eraseExpired();
        }

        // True if the key exists, otherwise false.
        return isset($this->cacheData[$key]);
    }

    /**
     * Stores data in the cache with an optional expiration time.
     *
     * @param string $key Unique identifier for the cached data.
     * @param mixed $data The data to cache.
     * @param string|null $expire Expiration time as a string (e.g., '+1 day').
     * @return self
     */
    public function store(string $key, mixed $data, ?string $expire = null): self
    {
        // Reload cache data if not loaded.
        $this->reload();

        // Push new entry into cacheData.
        $this->cacheData[$key] = [
            'time' => time(),
            'expire' => $expire !== null ? strtotime($expire) - time() : 0,
            'data' => serialize($data),
        ];

        // Changes applied, save this cache file.
        $this->changed = true;

        return $this;
    }

    /**
     * Loads data from cache or generates it using a callback if not present.
     *
     * @param string $key The cache key.
     * @param callable $callback Function to generate the data if not cached.
     * @param string|null $expire Optional expiration time.
     * @return mixed
     */
    public function load(string $key, callable $callback, ?string $expire = null): mixed
    {
        // Erase expired entries if enabled.
        if ($expire !== null) {
            $this->eraseExpired();
        }

        // Check if cache is already exists, else store it into cache. 
        if (!$this->has($key)) {
            $this->store($key, call_user_func($callback, $this), $expire);
        }

        // Retrieve entry from cache.
        return $this->retrieve($key);
    }

    /**
     * Retrieves data from the cache for given keys, optionally erasing expired entries.
     *
     * @param string|array $keys Cache key(s) to retrieve.
     * @param bool $eraseExpired Whether to erase expired entries before retrieval.
     * @return mixed
     */
    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed
    {
        // Erase expired entries if enabled.
        if ($eraseExpired) {
            $this->eraseExpired();
        }

        // Holds the cached entries, which are only retriving.
        $results = [];

        // Retrieve the cached entries.
        foreach ((array) $keys as $key) {
            if ($this->has($key)) {
                $results[$key] = unserialize($this->cacheData[$key]['data']);
            }
        }

        // The retrieved data or null if not found.
        return is_array($keys) ? $results : ($results[$keys] ?? null);
    }

    /**
     * Alias for retrieve method to get a single cache entry.
     *
     * @param string $key The cache key.
     * @param bool $eraseExpired Whether to erase expired entries before retrieval.
     * @return mixed The cached data or null if not found.
     */
    public function get(string $key, bool $eraseExpired = false): mixed
    {
        return $this->retrieve($key, $eraseExpired);
    }

    /**
     * Retrieves the metadata for the given cache key.
     *
     * @param string $key The cache key.
     * @return mixed The metadata for the given cache key, or null if not found.
     */
    public function metadata(string $key): mixed
    {
        return $this->cacheData[$key] ?? null;
    }

    /**
     * Retrieves all data from the cache, optionally erasing expired entries.
     *
     * @param bool $eraseExpired Whether to erase expired entries before retrieval.
     * @return array
     */
    public function retrieveAll(bool $eraseExpired = false): array
    {
        if ($eraseExpired) {
            $this->eraseExpired();
        }

        // An array of all cached data.
        return array_map(fn($entry) => unserialize($entry['data']), $this->cacheData);
    }

    /**
     * Erases specified cache entries.
     *
     * @param string|array ...$keys Cache key(s) to erase.
     * @return self
     */
    public function erase(string|array ...$keys): self
    {
        $keys = is_array($keys[0]) ? $keys[0] : $keys;
        $this->reload();

        // Remove the specified keys from cache data.
        foreach ($keys as $key) {
            unset($this->cacheData[$key]);
        }

        $this->changed = true;

        return $this;
    }

    /**
     * Deletes specified cache entries (alias for erase).
     *
     * @param string|array $keys Cache key(s) to delete.
     * @return self
     */
    public function delete(string|array $keys): self
    {
        return $this->erase($keys);
    }

    /**
     * Erases expired cache entries based on their timestamps and expiration times.
     *
     * @return self
     */
    public function eraseExpired(): self
    {
        $this->reload();

        if (!$this->erased) {
            $this->erased = true;
            foreach ($this->cacheData as $key => $entry) {
                if ($this->isExpired($entry['time'], $entry['expire'])) {
                    unset($this->cacheData[$key]);
                    $this->changed = true;
                }
            }
        }

        return $this;
    }

    /**
     * Retrieves all expired cache entries without removing them.
     *
     * @return array An associative array of expired cache entries.
     */
    public function getExpired(): array
    {
        $this->reload();
        $expired = [];

        foreach ($this->cacheData as $key => $entry) {
            if ($this->isExpired($entry['time'], $entry['expire'])) {
                $expired[$key] = unserialize($entry['data']);
            }
        }

        return $expired;
    }

    /**
     * Clears all cache data.
     *
     * @return self
     */
    public function flush(): self
    {
        $this->reload(); // Ensure cache is loaded before flushing

        $this->cacheData = [];
        $this->changed = true;

        return $this;
    }

    /**
     * Clears all cache data if the given condition is true.
     *
     * @param bool $condition The condition to check.
     * @return self
     */
    public function flushIf(bool $condition): self
    {
        if ($condition) {
            $this->flush();
        }

        return $this;
    }

    /**
     * Clears all cache data by deleting the cache file 
     * and resetting properties.
     *
     * @return self
     */
    public function clear(): self
    {
        if (file_exists($this->cachePath)) {
            unlink($this->cachePath);
        }

        $this->cacheData = [];
        $this->erased = false;
        $this->cached = false;
        $this->changed = false;

        return $this;
    }

    /**
     * Determines if a cache entry has expired.
     *
     * @param int $timestamp The creation timestamp of the entry.
     * @param int $expiration Expiration duration in seconds.
     * @return bool
     */
    private function isExpired(int $timestamp, int $expiration): bool
    {
        // True if expired, otherwise false.
        return $expiration !== 0 && ((time() - $timestamp) > $expiration);
    }

    /**
     * Saves the updated cache data to the filesystem if there are changes.
     *
     * Saves the updated cache data to the filesystem if there are changes.
     * It will create a new directory if the cache directory does not exist,
     * and throws an exception if the cache directory is not writable.
     *
     * @throws FailedToSaveCacheFileException Thrown if the cache directory is not writable.
     * @return void
     */
    public function saveChanges(): void
    {
        if ($this->changed) {
            // Set a temp directory to store caches. 
            $cacheDir = dir_path(config('cache_dir'));

            // Check if cache directory exists, else create a new directory.
            if (!fm()->ensureDirectoryWritable($cacheDir)) {
                throw new FailedToSaveCacheFileException("Cache directory is not writable.");
            }

            // Save updated cache data into local filesystem.
            file_put_contents(
                $this->cachePath,
                json_encode($this->cacheData, JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );

            // Log cache saved event in debug mode.
            if (env('debug')) {
                event('app:cache.saved', ['name' => $this->name, 'file' => $this->cachePath]);
            }

            $this->changed = false;
        }
    }

    /**
     * Destructor to save cache data to the filesystem if there are changes.
     */
    public function __destruct()
    {
        $this->saveChanges();
    }
}
