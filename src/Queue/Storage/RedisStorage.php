<?php

namespace Spark\Queue\Storage;

use Spark\Carbon;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\QueueStorageContract;
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
        $this->pushRedis($job, $queue, once: true);
    }

    public function clearAllJobs(): void
    {
        $allIds = $this->redis->sMembers($this->redisJobsSetKey()) ?: [];
        if (is_array($allIds)) {
            foreach ($allIds as $id) {
                $this->removeJobById((int) $id);
            }
        }

        $this->redis->del($this->redisJobsSetKey(), $this->redisFailedSetKey(), $this->redisQueuesSetKey());
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

        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        $repeat = $this->redisNullIfMissing((string) ($row['repeat'] ?? null)) ?? '';
        $dupe = $this->redisFingerprintKey($queue ?? 'default', is_array($payload) ? $payload : [], $repeat);
        $script = <<<'LUA'
redis.call('SREM', KEYS[1], ARGV[1])
redis.call('SREM', KEYS[2], ARGV[1])
redis.call('ZREM', KEYS[3], ARGV[1])
redis.call('ZREM', KEYS[4], ARGV[1])
redis.call('ZREM', KEYS[5], ARGV[1])
redis.call('SREM', KEYS[7], ARGV[1])
return redis.call('DEL', KEYS[6])
LUA;

        return $this->redis->eval($script, [
            $this->redisJobsSetKey(),
            $this->redisQueueJobsSetKey($queue ?? 'default'),
            $this->redisPendingSetKey($queue ?? 'default'),
            $this->redisReservedSetKey($queue ?? 'default'),
            $this->redisFailedSetKey(),
            $this->redisJobHashKey($id),
            $dupe,
            (string) $id,
        ], 7) === 1;
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

        if (
            !$this->transitionJob($bestId, [
                'status' => 'reserved',
                'reserved_at' => (string) $now,
            ], expectedStatus: 'pending')
        ) {
            return false;
        }

        $row = $this->getRedisJob($bestId);

        if (!$row) {
            return false;
        }

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
        $this->transitionJob($jobId, [
            'status' => $status,
            'attempts' => (string) max(0, $attempts),
            'reserved_at' => in_array($status, ['reserved', 'processing'], true) ? (string) time() : self::REDIS_NULL,
        ]);
    }

    public function rescheduleJob(int $jobId, Carbon $nextRun): void
    {
        $this->retryJob($jobId, $nextRun, 0);
    }

    public function markJobAsFailed(int $jobId, \Throwable $exception, int $attempts): void
    {
        $stack = $exception->getPrevious()?->getTraceAsString() ?? $exception->getTraceAsString();
        $exceptionText = sprintf("%s: %s\nStack trace:\n%s", get_class($exception), $exception->getMessage(), $stack);

        $this->transitionJob($jobId, [
            'status' => 'failed',
            'attempts' => (string) max(0, $attempts),
            'reserved_at' => self::REDIS_NULL,
            'failed_at' => (string) now(),
            'exception' => $exceptionText,
        ]);
    }

    public function retryJob(int $jobId, Carbon $retryTime, int $attempts): void
    {
        $this->transitionJob($jobId, [
            'scheduled_time' => $retryTime->utc()->format('Y-m-d H:i:s'),
            'status' => 'pending',
            'attempts' => (string) max(0, $attempts),
            'reserved_at' => self::REDIS_NULL,
        ], $retryTime->timestamp);
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

                $recovered += (int) $this->transitionJob((int) $id, [
                    'status' => 'pending',
                    'reserved_at' => self::REDIS_NULL,
                ], expectedStatus: $status, staleBefore: $staleBefore);
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

            $this->transitionJob((int) $id, [
                'status' => 'pending',
                'attempts' => '0',
                'reserved_at' => self::REDIS_NULL,
                'failed_at' => self::REDIS_NULL,
                'exception' => self::REDIS_NULL,
            ], expectedStatus: 'failed');
        }
    }

    private function pushRedis(JobContract $job, string $queue, ?int $jobId = null, bool $once = false): void
    {
        $payload = $this->serializeJob($job);
        $jobId = $jobId ?? $this->nextRedisId();
        $payloadData = json_encode([
            'callback' => $payload['callback'],
            'parameters' => $payload['parameters'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

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

        $pairs = [];

        foreach (array_map($this->redisNormalizeValue(...), $row) as $field => $value) {
            $pairs[] = $field;
            $pairs[] = $value;
        }

        $script = <<<'LUA'
if ARGV[4] == '1' and redis.call('SCARD', KEYS[6]) > 0 then
    return 0
end
redis.call('HSET', KEYS[5], unpack(ARGV, 5))
redis.call('SADD', KEYS[1], ARGV[1])
redis.call('SADD', KEYS[2], ARGV[1])
redis.call('SADD', KEYS[3], ARGV[2])
redis.call('ZADD', KEYS[4], ARGV[3], ARGV[1])
redis.call('SADD', KEYS[6], ARGV[1])
return 1
LUA;

        $this->redis->eval($script, [
            $this->redisJobsSetKey(),
            $this->redisQueueJobsSetKey($queue),
            $this->redisQueuesSetKey(),
            $this->redisPendingSetKey($queue),
            $this->redisJobHashKey($jobId),
            $this->redisFingerprintKey($queue, [
                'callback' => $payload['callback'],
                'parameters' => $payload['parameters'],
            ], $payload['repeat'] ?? ''),
            (string) $jobId,
            $queue,
            (string) $this->redisToTimestamp((string) $payload['scheduledTime']),
            $once ? '1' : '0',
            ...$pairs,
        ], 6);
    }

    /** Atomically update a job and all indexes; never recreate a removed job. */
    private function transitionJob(
        int $id,
        array $updates,
        ?int $scheduled = null,
        ?string $expectedStatus = null,
        ?int $staleBefore = null,
    ): bool {
        $row = $this->getRedisJob($id);

        if ($row === null) {
            return false;
        }

        $queue = $row['queue'];
        $pairs = [];

        foreach ($updates as $field => $value) {
            $pairs[] = $field;
            $pairs[] = $value;
        }

        $script = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
if ARGV[4] ~= '' and redis.call('HGET', KEYS[1], 'status') ~= ARGV[4] then return 0 end
if ARGV[4] == 'pending' then
    local score = tonumber(redis.call('ZSCORE', KEYS[2], ARGV[1]))
    if not score or score > tonumber(ARGV[3]) then return 0 end
end
if ARGV[5] ~= '' then
    local reserved = tonumber(redis.call('HGET', KEYS[1], 'reserved_at'))
    if not reserved or reserved >= tonumber(ARGV[5]) then return 0 end
end
redis.call('HSET', KEYS[1], unpack(ARGV, 6))
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('ZREM', KEYS[3], ARGV[1])
redis.call('ZREM', KEYS[4], ARGV[1])
local status = redis.call('HGET', KEYS[1], 'status')
if status == 'pending' then redis.call('ZADD', KEYS[2], ARGV[2], ARGV[1]) end
if status == 'reserved' or status == 'processing' then redis.call('ZADD', KEYS[3], ARGV[3], ARGV[1]) end
if status == 'failed' then redis.call('ZADD', KEYS[4], ARGV[3], ARGV[1]) end
return 1
LUA;

        return $this->redis->eval($script, [
            $this->redisJobHashKey($id),
            $this->redisPendingSetKey($queue),
            $this->redisReservedSetKey($queue),
            $this->redisFailedSetKey(),
            (string) $id,
            (string) ($scheduled ?? $this->redisToTimestamp($row['scheduled_time'])),
            (string) time(),
            $expectedStatus ?? '',
            $staleBefore === null ? '' : (string) $staleBefore,
            ...$pairs,
        ], 4) === 1;
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

        return (new Carbon($value, 'UTC'))->timestamp;
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

}
