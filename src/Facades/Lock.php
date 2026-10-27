<?php

namespace Spark\Facades;

use Spark\Cache\Cache;
use Spark\Cache\CacheManager;
use Spark\Cache\LockHandle;

/**
 * Namespace factories share repositories; lock handles always have independent owners.
 *
 * @method static Cache make(string $name = 'default')
 * @method static Cache store(?string $name = null)
 * @method static LockHandle lock(string $key, int $seconds = 10, ?string $owner = null)
 * @method static LockHandle restoreLock(string $key, string $owner)
 */
class Lock extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CacheManager::class;
    }
}
