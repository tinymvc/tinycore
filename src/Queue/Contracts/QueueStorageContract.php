<?php

namespace Spark\Queue\Contracts;

use PDO;
use Spark\Carbon;
use Spark\Queue\Contracts\JobContract;

interface QueueStorageContract
{
    public function getPdoConnection(): ?PDO;

    public function push(JobContract $job, string $queue = 'default'): void;

    public function pushOnce(JobContract $job, string $queue = 'default'): void;

    public function clearAllJobs(): void;

    public function clearRepeatedJobs(): void;

    public function clearFailedJobs(): void;

    public function removeJobById(int $id): bool;

    public function removeQueue(string $name): bool;

    public function getNextJob(array|string $queue = 'default'): false|JobContract;

    public function updateJobStatus(int $jobId, string $status, int $attempts): void;

    public function rescheduleJob(int $jobId, Carbon $nextRun): void;

    public function markJobAsFailed(int $jobId, \Throwable $exception, int $attempts): void;

    public function retryJob(int $jobId, Carbon $retryTime, int $attempts): void;

    public function recoverStaleJobs(int $timeout = 3600): int;

    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array;

    public function getFailedJobs(int $from = 0, int $to = 500): array;

    public function retryFailedJobs(): void;
}
