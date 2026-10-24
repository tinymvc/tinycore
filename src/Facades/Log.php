<?php

namespace Spark\Facades;

use Psr\Log\LoggerInterface;
use Spark\Logger;

/**
 * @method static void log($level, string|\Stringable $message, array $context = [])
 * @method static void emergency(string|\Stringable $message, array $context = [])
 * @method static void alert(string|\Stringable $message, array $context = [])
 * @method static void critical(string|\Stringable $message, array $context = [])
 * @method static void error(string|\Stringable $message, array $context = [])
 * @method static void warning(string|\Stringable $message, array $context = [])
 * @method static void notice(string|\Stringable $message, array $context = [])
 * @method static void info(string|\Stringable $message, array $context = [])
 * @method static void debug(string|\Stringable $message, array $context = [])
 */
class Log extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LoggerInterface::class;
    }

    public static function getFacadeRoot(): LoggerInterface
    {
        return parent::getFacadeRoot() ?? new Logger();
    }
}
