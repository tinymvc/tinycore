<?php

namespace Spark\Facades;

use Spark\Http\Session as BaseSession;

/**
 * Facade Session
 *
 * Static interface to the application session manager.
 *
 * @method static void start()
 * @method static bool isStarted(bool $forceStart = false)
 * @method static string id()
 * @method static mixed get(string $key, $default = null)
 * @method static void set(string $key, $value)
 * @method static bool has(string $key)
 * @method static void delete(string $key)
 * @method static void put(string|array $key, $value = null)
 * @method static void forget(string|array $keys)
 * @method static array all()
 * @method static void flush()
 * @method static mixed pull(string $key, $default = null)
 * @method static bool invalidate(bool $deleteOldSession = true)
 * @method static bool regenerate(bool $deleteOldSession = false)
 * @method static void destroy()
 * @method static void close()
 * @method static void flash(string $key, $value)
 * @method static mixed getFlash(string $key, $default = null)
 * @method static bool hasFlash(string $key)
 * @method static void clearFlash()
 *
 * @package Spark\Facades
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Session extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseSession::class;
    }
}
