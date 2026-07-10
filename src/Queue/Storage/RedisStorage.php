<?php

namespace Spark\Queue\Storage;

use Spark\Carbon;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\QueueStorageContract;
use Spark\Queue\Exceptions\FailedToSaveJobsException;
use Spark\Utils\RedisConnector;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function get_class;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function ltrim;
use function max;
use function md5;
use function sha1;
use function sprintf;
use function time;
use function trim;
use function usort;

class RedisStorage implements QueueStorageContract
{
    use SerializesJobs;

    private const REDIS_NULL = '__spark_null__';

    private \Redis $redis;

    private string $redisPrefix = 'spark:queue';

    public function __construct(array $config)
    {
        $redisConfig = RedisConnector::resolveConnectionConfig($config);
        $this->redis = RedisConnector::make($redisConfig, 'redis');

        $prefix = trim((string) ($redisConfig['prefix'] ?? 'spark'));
        if ($prefix === '') {
            $prefix = 'spark';
        }

        $prefix = trim($prefix, ':');
        $this->redisPrefix = sprintf('%s:queue:%s', $prefix, md5('redis'));
        $this->pruneStaleFingerprintKeys();
    }

    public function getConnection(): \Redis
    {
        return $this->redis;
    }

    public function push(JobContract $job, string $queue = 'default'): void
    {
        $this->pushRedis($job, $queue);
    }

    public function pushOnce(JobContract $job, string $queue = 'default'): void
    {
        $payload = $this->serializeJob($job);
        $dupeKey = $this->redisFingerprintKey($queue, [
            'callback' => $payload['callback'],
            'parameters' => $payload['parameters'],
        ], $payload['repeat'] ?? '');

        $existing = $this->redis->get($dupeKey);
        if (is_string($existing) && $existing !== '') {
            $existingJob = $this->getRedisJob((int) $existing);
            if ($existingJob !== null && $this->redisToValue($existingJob['status'] ?? '') !== null) {
                return;
            }

            $this->redis->del($dupeKey);
        }

        $jobId = $this->nextRedisId();

        if ($this->redis->setnx($dupeKey, (string) $jobId) !== true) {
            return;
        }

        try {
            $this->pushRedis($job, $queue, $jobId);
        } catch (\Throwable $e) {
            $this->redis->del($dupeKey);
            throw new FailedToSaveJobsException('Failed to add job to the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function clearAllJobs(): void
    {
        $allIds = $this->redis->sMembers($this->redisJobsSetKey()) ?: [];
        if (is_array($allIds)) {
            foreach ($allIds as $id) {
                $this->removeJobById((int) $id);
            }
        }

        $this->redis->del($this->redisJobsSetKey(), $this->redisFailedSetKey(), $this->redisQueuesSetKey(), $this->redisNextIdKey());
        $this->pruneStaleFingerprintKeys();
    }

    public function clearRepeatedJobs(): void
    {
        $this->clearJobsByFilter(fn(array $job): bool => (string) ($job['repeat'] ?? '') !== '');
    }

    public function clearFailedJobs(): void
    {
        $this->clearJobsByFilter(fn(array $job): bool => (string) ($job['status'] ?? '') === 'failed');
    }

    public function removeJobById(int $id): bool
    {
        $row = $this->getRedisJob($id);
        if (!$row) {
            return false;
        }

        $queue = $this->redisNullIfMissing((string) ($row['queue'] ?? 'default'));

        $this->redis->sRem($this->redisJobsSetKey(), $id);
        if ($queue !== null) {
            $this->redis->sRem($this->redisQueueJobsSetKey($queue), $id);
            $this->redis->zRem($this->redisPendingSetKey($queue), (string) $id);
            $this->redis->zRem($this->redisReservedSetKey($queue), (string) $id);
        }

        $this->redis->zRem($this->redisFailedSetKey(), (string) $id);
        $this->redis->del($this->redisJobHashKey($id));

        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        if (is_array($payload)) {
            $repeat = $this->redisNullIfMissing((string) ($row['repeat'] ?? null)) ?? '';
            $this->redis->del($this->redisFingerprintKey($queue ?? 'default', $payload, $repeat));
        }

        return true;
    }

    public function removeQueue(string $name): bool
    {
        $ids = $this->redis->sMembers($this->redisQueueJobsSetKey($name)) ?: [];
        $removed = false;

        if (is_array($ids)) {
            foreach ($ids as $id) {
                $removed = $this->removeJobById((int) $id) || $removed;
            }
        }

        $this->redis->del(
            $this->redisQueueJobsSetKey($name),
            $this->redisPendingSetKey($name),
            $this->redisReservedSetKey($name)
        );

        if ($removed) {
            $this->redis->sRem($this->redisQueuesSetKey(), $name);
        }

        return $removed;
    }

    public function getNextJob(array|string $queue = 'default'): false|JobContract
    {
        $queues = $this->toQueueList($queue);
        if ($queues === []) {
            return false;
        }

        $bestQueue = null;
        $bestId = null;
        $bestScore = null;
        $now = time();

        foreach ($queues as $queueName) {
            $jobs = $this->redis->zRangeByScore(
                $this->redisPendingSetKey($queueName),
                '-inf',
                $now,
                ['withscores' => true, 'limit' => [0, 1]]
            );

            if (!$jobs || count($jobs) === 0) {
                continue;
            }

            $jobId = (int) array_key_first($jobs);
            $score = (int) (array_values($jobs)[0] ?? 0);

            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestQueue = $queueName;
                $bestId = $jobId;
            }
        }

        if ($bestQueue === null || $bestId === null) {
            return false;
        }

        if ($this->redis->zRem($this->redisPendingSetKey($bestQueue), (string) $bestId) === 0) {
            return false;
        }

        $row = $this->getRedisJob($bestId);
        if (!$row) {
            return false;
        }

        $this->updateJobStatus($bestId, 'reserved', $this->redisToIntValue($row['attempts'] ?? '0'));

        return $this->unserializeJob([
            'id' => $bestId,
            'payload' => $row['payload'] ?? '{}',
            'queue' => $row['queue'] ?? $bestQueue,
            'scheduled_time' => $row['scheduled_time'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'repeat' => $this->redisNullIfMissing($row['repeat'] ?? null),
            'status' => $row['status'] ?? 'pending',
            'attempts' => $this->redisToIntValue($row['attempts'] ?? '0'),
            'reserved_at' => $this->redisNullIfMissing($row['reserved_at'] ?? null),
            'exception' => $this->redisNullIfMissing($row['exception'] ?? null),
            'failed_at' => $this->redisNullIfMissing($row['failed_at'] ?? null),
        ]);
    }

    public function updateJobStatus(int $jobId, string $status, int $attempts): void
    {
        $job = $this->getRedisJob($jobId);
        if (!$job) {
            return;
        }

        $queue = $this->redisNullIfMissing((string) ($job['queue'] ?? 'default')) ?? 'default';
        $scheduled = $this->redisToTimestamp($job['scheduled_time'] ?? null);

        $pendingSet = $this->redisPendingSetKey($queue);
        $reservedSet = $this->redisReservedSetKey($queue);

        $this->redis->zRem($pendingSet, (string) $jobId);
        $this->redis->zRem($reservedSet, (string) $jobId);

        $payload = [
            'status' => $status,
            'attempts' => (string) max(0, $attempts),
            'reserved_at' => self::REDIS_NULL,
            'failed_at' => $job['failed_at'] ?? self::REDIS_NULL,
            'exception' => $job['exception'] ?? self::REDIS_NULL,
        ];

        if (in_array($status, ['reserved', 'processing'], true)) {
            $payload['reserved_at'] = (string) now()->timestamp;
            $this->redis->zAdd($reservedSet, now()->timestamp, (string) $jobId);
        } elseif ($status === 'pending') {
            $this->redis->zAdd($pendingSet, $scheduled, (string) $jobId);
        }

        $this->redis->hMSet($this->redisJobHashKey($jobId), array_map($this->redisNormalizeValue(...), $payload));
    }

    public function rescheduleJob(int $jobId, Carbon $nextRun): void
    {
        $job = $this->getRedisJob($jobId);
        if (!$job) {
            return;
        }

        $queue = $this->redisNullIfMissing((string) ($job['queue'] ?? 'default')) ?? 'default';
        $this->redis->hMSet($this->redisJobHashKey($jobId), [
            'scheduled_time' => (string) $nextRun,
            'status' => 'pending',
            'attempts' => '0',
            'reserved_at' => self::REDIS_NULL,
        ]);

        $this->redis->zAdd($this->redisPendingSetKey($queue), $nextRun->timestamp, (string) $jobId);
        $this->redis->zRem($this->redisReservedSetKey($queue), (string) $jobId);
    }

    public function markJobAsFailed(int $jobId, \Throwable $exception, int $attempts): void
    {
        $row = $this->getRedisJob($jobId);
        if (!$row) {
            return;
        }

        $queue = $this->redisNullIfMissing((string) ($row['queue'] ?? 'default')) ?? 'default';
        $stack = $exception->getPrevious()?->getTraceAsString() ?? $exception->getTraceAsString();
        $exceptionText = sprintf('%s: %s\nStack trace:\n%s', get_class($exception), $exception->getMessage(), $stack);
        $failedAt = now();

        $this->redis->hMSet($this->redisJobHashKey($jobId), [
            'status' => 'failed',
            'attempts' => (string) $attempts,
            'reserved_at' => self::REDIS_NULL,
            'failed_at' => (string) $failedAt,
            'exception' => $exceptionText,
        ]);

        $this->redis->zRem($this->redisReservedSetKey($queue), (string) $jobId);
        $this->redis->zAdd($this->redisFailedSetKey(), $failedAt->timestamp, (string) $jobId);
    }

    public function retryJob(int $jobId, Carbon $retryTime, int $attempts): void
    {
        $job = $this->getRedisJob($jobId);
        if (!$job) {
            return;
        }

        $queue = $this->redisNullIfMissing((string) ($job['queue'] ?? 'default')) ?? 'default';
        $this->redis->hMSet($this->redisJobHashKey($jobId), [
            'scheduled_time' => (string) $retryTime,
            'status' => 'pending',
            'attempts' => (string) $attempts,
            'reserved_at' => self::REDIS_NULL,
        ]);

        $this->redis->zAdd($this->redisPendingSetKey($queue), $retryTime->timestamp, (string) $jobId);
        $this->redis->zRem($this->redisReservedSetKey($queue), (string) $jobId);
        $this->redis->zRem($this->redisFailedSetKey(), (string) $jobId);
    }

    public function recoverStaleJobs(int $timeout = 3600): int
    {
        $staleBefore = time() - $timeout;
        $queues = $this->redis->sMembers($this->redisQueuesSetKey()) ?: [];
        if (!is_array($queues)) {
            return 0;
        }

        $recovered = 0;

        foreach ($queues as $queue) {
            $reserved = $this->redis->zRangeByScore($this->redisReservedSetKey((string) $queue), '-inf', $staleBefore, ['withscores' => true]);
            if (!is_array($reserved)) {
                continue;
            }

            foreach (array_keys($reserved) as $id) {
                $row = $this->getRedisJob((int) $id);
                if (!$row) {
                    $this->redis->zRem($this->redisReservedSetKey((string) $queue), (string) $id);
                    continue;
                }

                $status = $this->redisNullIfMissing((string) ($row['status'] ?? ''));
                if ($status !== 'processing' && $status !== 'reserved') {
                    $this->redis->zRem($this->redisReservedSetKey((string) $queue), (string) $id);
                    continue;
                }

                $this->updateJobStatus((int) $id, 'pending', $this->redisToIntValue($row['attempts'] ?? '0'));
                $recovered++;
            }
        }

        return $recovered;
    }

    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array {
        if ($queue === null) {
            $ids = $this->redis->sMembers($this->redisJobsSetKey()) ?: [];
        } else {
            $ids = [];
            foreach ($this->toQueueList($queue) as $name) {
                $queueIds = $this->redis->sMembers($this->redisQueueJobsSetKey($name));
                if (is_array($queueIds)) {
                    $ids = array_merge($ids, $queueIds);
                }
            }
            $ids = array_unique(array_map('intval', $ids));
        }

        if (!is_array($ids)) {
            return [];
        }

        $statusList = $this->toStatusList($status);
        $jobs = [];

        foreach ($ids as $id) {
            $row = $this->getRedisJob((int) $id);
            if (!$row) {
                continue;
            }

            $statusValue = $this->redisNullIfMissing((string) ($row['status'] ?? 'pending'));
            if ($statusList !== [] && !in_array($statusValue, $statusList, true)) {
                continue;
            }

            $jobs[] = $this->unserializeJob($this->normalizeRedisRow((int) $id, $row));
        }

        usort($jobs, static fn(JobContract $a, JobContract $b) => $a->getScheduledTime()->timestamp <=> $b->getScheduledTime()->timestamp);

        return array_slice($jobs, $from, max(0, $to));
    }

    public function getFailedJobs(int $from = 0, int $to = 500): array
    {
        $ids = $this->redis->zRevRange($this->redisFailedSetKey(), $from, $from + max(0, $to - 1)) ?: [];
        if (!is_array($ids)) {
            return [];
        }

        $jobs = [];
        foreach ($ids as $id) {
            $row = $this->getRedisJob((int) $id);
            if (!$row) {
                continue;
            }

            $jobs[] = $this->unserializeJob($this->normalizeRedisRow((int) $id, $row));
        }

        return $jobs;
    }

    public function retryFailedJobs(): void
    {
        $ids = $this->redis->zRevRange($this->redisFailedSetKey(), 0, -1) ?: [];
        if (!is_array($ids)) {
            return;
        }

        foreach ($ids as $id) {
            $job = $this->getRedisJob((int) $id);
            if (!$job) {
                continue;
            }

            $row = $this->normalizeRedisRow((int) $id, $job);
            $this->redis->hMSet($this->redisJobHashKey((int) $id), [
                'status' => 'pending',
                'attempts' => '0',
                'reserved_at' => self::REDIS_NULL,
                'failed_at' => self::REDIS_NULL,
                'exception' => self::REDIS_NULL,
            ]);

            $this->redis->zAdd($this->redisPendingSetKey($row['queue'] ?? 'default'), $this->redisToTimestamp($row['scheduled_time'] ?? null), (string) $id);
            $this->redis->zRem($this->redisFailedSetKey(), (string) $id);
        }
    }

    private function pushRedis(JobContract $job, string $queue, ?int $jobId = null): void
    {
        $payload = $this->serializeJob($job);
        $jobId = $jobId ?? $this->nextRedisId();
        $payloadData = json_encode([
            'callback' => $payload['callback'],
            'parameters' => $payload['parameters'],
        ], JSON_UNESCAPED_UNICODE);

        $row = [
            'id' => (string) $jobId,
            'payload' => $payloadData,
            'queue' => $queue,
            'scheduled_time' => $payload['scheduledTime'],
            'created_at' => (string) now(),
            'repeat' => $payload['repeat'] ?? self::REDIS_NULL,
            'status' => 'pending',
            'attempts' => '0',
            'reserved_at' => self::REDIS_NULL,
            'exception' => self::REDIS_NULL,
            'failed_at' => self::REDIS_NULL,
        ];

        $this->redis->hMSet($this->redisJobHashKey($jobId), array_map($this->redisNormalizeValue(...), $row));
        $this->redis->sAdd($this->redisJobsSetKey(), $jobId);
        $this->redis->sAdd($this->redisQueueJobsSetKey($queue), $jobId);
        $this->redis->sAdd($this->redisQueuesSetKey(), $queue);
        $this->redis->zAdd($this->redisPendingSetKey($queue), $this->redisToTimestamp((string) $payload['scheduledTime']), (string) $jobId);

        $this->redis->set($this->redisFingerprintKey($queue, [
            'callback' => $payload['callback'],
            'parameters' => $payload['parameters'],
        ], $payload['repeat'] ?? ''), (string) $jobId);
    }

    private function clearJobsByFilter(callable $filter): void
    {
        $allIds = $this->redis->sMembers($this->redisJobsSetKey()) ?: [];
        if (!is_array($allIds)) {
            return;
        }

        foreach ($allIds as $id) {
            $row = $this->getRedisJob((int) $id);
            if (!$row) {
                continue;
            }

            if ($filter($this->normalizeRedisRow((int) $id, $row))) {
                $this->removeJobById((int) $id);
            }
        }
    }

    private function normalizeRedisRow(int $id, array $row): array
    {
        return [
            'id' => $id,
            'payload' => $row['payload'] ?? '{}',
            'queue' => $this->redisNullIfMissing((string) ($row['queue'] ?? 'default')),
            'scheduled_time' => $this->redisNullIfMissing((string) ($row['scheduled_time'] ?? null)),
            'created_at' => $this->redisNullIfMissing((string) ($row['created_at'] ?? null)),
            'repeat' => $this->redisNullIfMissing((string) ($row['repeat'] ?? null)),
            'status' => $this->redisNullIfMissing((string) ($row['status'] ?? 'pending')),
            'attempts' => $this->redisToIntValue((string) ($row['attempts'] ?? '0')),
            'reserved_at' => $this->redisNullIfMissing((string) ($row['reserved_at'] ?? null)),
            'exception' => $this->redisNullIfMissing((string) ($row['exception'] ?? null)),
            'failed_at' => $this->redisNullIfMissing((string) ($row['failed_at'] ?? null)),
        ];
    }

    private function getRedisJob(int $id): ?array
    {
        $row = $this->redis->hGetAll($this->redisJobHashKey($id));

        return $row === [] ? null : $row;
    }

    private function nextRedisId(): int
    {
        return (int) $this->redis->incr($this->redisNextIdKey());
    }

    private function redisKey(string $key): string
    {
        return $this->redisPrefix . ':' . ltrim($key, ':');
    }

    private function redisJobsSetKey(): string
    {
        return $this->redisKey('jobs');
    }

    private function redisQueueJobsSetKey(string $queue): string
    {
        return $this->redisKey("jobs:queue:$queue");
    }

    private function redisQueuesSetKey(): string
    {
        return $this->redisKey('queues');
    }

    private function redisPendingSetKey(string $queue): string
    {
        return $this->redisKey("pending:$queue");
    }

    private function redisReservedSetKey(string $queue): string
    {
        return $this->redisKey("reserved:$queue");
    }

    private function redisJobHashKey(int $id): string
    {
        return $this->redisKey("job:$id");
    }

    private function redisFailedSetKey(): string
    {
        return $this->redisKey('failed');
    }

    private function redisNextIdKey(): string
    {
        return $this->redisKey('next_id');
    }

    private function redisFingerprintKey(string $queue, array $payload, ?string $repeat): string
    {
        $fingerprint = sha1(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $this->redisKey("dupe:$queue:$repeat:$fingerprint");
    }

    private function redisNormalizeValue(mixed $value): string
    {
        return match (true) {
            $value === null => self::REDIS_NULL,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private function redisToValue(string $value): ?string
    {
        return $value === self::REDIS_NULL ? null : $value;
    }

    private function redisToIntValue(string $value): int
    {
        return (int) ($value === self::REDIS_NULL ? 0 : $value);
    }

    private function redisToTimestamp(null|string $value): int
    {
        if ($value === null || $value === self::REDIS_NULL || $value === '') {
            return time();
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? time() : $timestamp;
    }

    private function redisNullIfMissing(?string $value): ?string
    {
        return ($value === self::REDIS_NULL || $value === '') ? null : $value;
    }

    private function toQueueList(array|string|null $queue): array
    {
        if ($queue === null || $queue === '') {
            return [];
        }

        $queues = is_array($queue) ? $queue : explode(',', $queue);
        $queues = array_map('trim', $queues);
        $queues = array_filter($queues);

        return array_values(array_unique($queues));
    }

    private function toStatusList(array|string|null $status): array
    {
        if ($status === null || $status === '') {
            return [];
        }

        $statuses = is_array($status) ? $status : explode(',', $status);
        $statuses = array_map('trim', $statuses);
        $statuses = array_filter($statuses);

        return array_values(array_unique($statuses));
    }

    private function pruneStaleFingerprintKeys(): void
    {
        $cursor = 0;
        $dupeKeys = [];

        do {
            $keys = $this->redis->scan($cursor, $this->redisPrefix . ':dupe:*');
            if ($keys !== false) {
                foreach ((array) $keys as $key) {
                    $dupeKeys[] = (string) $key;
                }
            }
        } while ($cursor > 0);

        foreach ($dupeKeys as $dupeKey) {
            $jobId = $this->redis->get($dupeKey);
            if (!is_string($jobId) || $jobId === '') {
                $this->redis->del($dupeKey);
                continue;
            }

            if ($this->getRedisJob((int) $jobId) === null) {
                $this->redis->del($dupeKey);
            }
        }
    }
}
