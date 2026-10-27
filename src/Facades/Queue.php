<?php

namespace Spark\Facades;

use Spark\Queue\Queue as BaseQueue;
use Spark\Queue\QueueManager;
use Spark\Queue\Contracts\JobContract;

/**
 * Facade Queue
 *
 * This class provides a static interface to the underlying Queue service.
 *
 * @method static BaseQueue connection(?string $name = null)
 * @method static void purge(?string $name = null)
 * @method static \PDO|\Redis getConnection()
 * @method static void push(JobContract $job, string $queue = 'default')
 * @method static void pushOnce(JobContract $job, string $queue = 'default')
 * @method static void restart()
 * @method static void work(bool $once = false, int $timeout = 3600, int $sleep = 3, int $delay = 5, int $tries = 3, array|string $queue = 'default')
 * @method static void clearAllJobs()
 * @method static void clearRepeatedJobs()
 * @method static void clearFailedJobs()
 * @method static bool removeJobById(int $id)
 * @method static bool removeQueue(string $name)
 * @method static array getJobs(array|string|null $queue = null, array|string|null $status = null, int $from = 0, int $to = 500)
 * @method static array getFailedJobs(int $from = 0, int $to = 500)
 * @method static void retryFailedJobs()
 *
 * @package Spark\Facades
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Queue extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return QueueManager::class;
    }
}
