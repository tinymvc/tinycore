<?php

namespace Spark\Facades;

use Spark\Utils\Lock as BaseLock;

/**
 * Facade for Lock utility.
 * 
 * Provides static access to lock management methods.
 * 
 * @method static bool lock(string $key, int $timeout = 10, int $waitTimeout = 5)
 * @method static bool unlock(string $key)
 * @method static int unlockAll()
 * @method static bool forceUnlock(string $key)
 * @method static int releaseExpiredLocks()
 * @method static bool isLocked(string $key)
 * @method static bool ownsLock(string $key)
 * @method static bool extendLock(string $key, int $additionalSeconds)
 * @method static mixed withLock(string $key, callable $callback, int $timeout = 10, int $waitTimeout = 5)
 * @method static string getLockOwner()
 * @method static null|array getLockInfo(string $key)
 * @method static void optimize()
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Lock extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseLock::class;
    }
}
