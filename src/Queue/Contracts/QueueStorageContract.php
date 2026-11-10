<?php

namespace Spark\Queue\Contracts;

use Spark\Carbon;
use Spark\Queue\Contracts\JobContract;

interface QueueStorageContract
{
    public function push(JobContract $job, string $queue = 'default'): bool;

    public function pushOnce(JobContract $job, string $queue = 'default'): bool;

    public function clearAllJobs(): void;

    public function clearRepeatedJobs(): void;

    public function clearFailedJobs(): void;

    public function removeJobById(string $id): bool;

    public function removeQueue(string $name): bool;

    public function getNextJob(array|string $queue = 'default'): false|JobContract;

    public function updateJobStatus(string $jobId, string $status, int $attempts): void;

    public function rescheduleJob(string $jobId, Carbon $nextRun): void;

    public function markJobAsFailed(string $jobId, \Throwable $exception, int $attempts): void;

    public function retryJob(string $jobId, Carbon $retryTime, int $attempts): void;

    public function recoverStaleJobs(int $timeout = 3600): int;

    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array;

    public function getFailedJobs(int $from = 0, int $to = 500): array;

    public function retryFailedJobs(?string $id = null): void;
}
