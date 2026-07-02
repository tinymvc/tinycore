<?php

namespace Spark\Cache\Storage;

use Spark\Cache\Contracts\CacheStorageContract;
use Spark\Utils\RedisConnector;
use function array_key_exists;
use function array_map;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function md5;
use function sprintf;
use function time;
use function trim;

class RedisStorage implements CacheStorageContract
{
    private \Redis $redis;

    private string $cacheKeyPrefix = 'cache:';

    private string $lockNamespace = 'lock';

    private const REDIS_OWNER_KEY = 'owner';
    private const REDIS_LOCKED_AT_KEY = 'locked_at';
    private const REDIS_EXPIRE_AT_KEY = 'expire_at';

    public function __construct(
        private readonly string $name,
        array $config,
    ) {
        $connection = RedisConnector::resolveConnectionConfig($config);
        $this->redis = RedisConnector::make($connection, $name);

        $prefix = trim((string) ($connection['prefix'] ?? 'spark'));
        if ($prefix === '') {
            $prefix = 'spark';
        }

        $prefix = trim($prefix, ':');
        $hash = md5($name);
        $this->cacheKeyPrefix = sprintf('%s:cache:%s:', $prefix, $hash);
        $this->lockNamespace = sprintf('%s:lock:%s', $prefix, $hash);
    }

    public function has(string $key, bool $eraseExpired = false): bool
    {
        $eraseExpired && $this->cleanupExpired();

        $redisKey = $this->cacheKey($key);
        $raw = $this->redis->get($redisKey);
        if ($raw === false) {
            return false;
        }

        $payload = $this->unpackValue($raw);
        if (!is_array($payload)) {
            return false;
        }

        if ($this->isExpiredPayload($payload)) {
            $this->redis->del($redisKey);
            return false;
        }

        return true;
    }

    public function store(string $key, mixed $data, null|string $expire = null): void
    {
        $packed = $this->packValue($key, $data, $expire);
        $redisKey = $this->cacheKey($key);

        $this->redis->set($redisKey, $packed['payload']);

        if ($packed['expire_at'] > 0) {
            $this->redis->expire($redisKey, max(1, $packed['expire_at'] - time()));
        }
    }

    public function retrieve(string|array $keys, bool $eraseExpired = false): mixed
    {
        if ($eraseExpired) {
            $this->cleanupExpired();
        }

        if (is_array($keys)) {
            if ($keys === []) {
                return [];
            }

            $redisKeys = array_map($this->cacheKey(...), $keys);
            $values = $this->redis->mget($redisKeys);
            $results = [];

            foreach ($keys as $index => $key) {
                $payload = $this->unpackValue($values[$index] ?? false);

                if (!is_array($payload) || $this->isExpiredPayload($payload)) {
                    continue;
                }

                $results[$key] = $payload['value'];
            }

            return $results;
        }

        $payload = $this->unpackValue($this->redis->get($this->cacheKey($keys)));

        if (!is_array($payload) || $this->isExpiredPayload($payload)) {
            return null;
        }

        return $payload['value'];
    }

    public function metadata(string $key): ?array
    {
        $row = $this->unpackValue($this->redis->get($this->cacheKey($key)));

        if (!is_array($row)) {
            return null;
        }

        return [
            'created_at' => $row['created_at'],
            'expire_at' => $row['expire_at'],
        ];
    }

    public function retrieveAll(bool $eraseExpired = false): array
    {
        if ($eraseExpired) {
            $this->cleanupExpired();
        }

        $result = [];
        $this->scanKeys($this->cachePrefixPattern(), function (string $redisKey) use (&$result) {
            $payload = $this->unpackValue($this->redis->get($redisKey));

            if (!is_array($payload) || $this->isExpiredPayload($payload)) {
                return;
            }

            $key = substr($redisKey, strlen($this->cacheKeyPrefix));
            $result[$key] = $payload['value'];
        });

        return $result;
    }

    public function erase(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->redis->del(array_map($this->cacheKey(...), $keys));
    }

    public function eraseExpired(): int
    {
        return $this->cleanupExpired();
    }

    public function getExpired(): array
    {
        $result = [];
        $this->scanKeys($this->cachePrefixPattern(), function (string $redisKey) use (&$result) {
            $payload = $this->unpackValue($this->redis->get($redisKey));

            if (!is_array($payload) || !$this->isExpiredPayload($payload)) {
                return;
            }

            $key = substr($redisKey, strlen($this->cacheKeyPrefix));
            $result[$key] = $payload['value'];
        });

        return $result;
    }

    public function flush(): void
    {
        $keys = [];
        $this->scanKeys($this->cachePrefixPattern(), function (string $redisKey) use (&$keys) {
            $keys[] = $redisKey;
        });

        if ($keys !== []) {
            $this->redis->del($keys);
        }
    }

    public function clear(): void
    {
        $this->flush();
    }

    public function storeMany(array $items, null|string $expire = null): void
    {
        foreach ($items as $key => $value) {
            $this->store((string) $key, $value, $expire);
        }
    }

    public function increment(string $key, int $amount = 1): int|false
    {
        $value = $this->retrieve($key);
        if (!is_numeric($value)) {
            return false;
        }

        $value = (int) $value + $amount;
        $metadata = $this->metadata($key);
        $expireAt = (int) ($metadata['expire_at'] ?? 0);
        $expire = $expireAt > 0 ? ('+' . max(1, $expireAt - time()) . ' seconds') : null;

        $this->store($key, $value, $expire);
        return $value;
    }

    public function add(string $key, mixed $value, null|string $expire = null): bool
    {
        $packed = $this->packValue($key, $value, $expire);
        $options = ['nx'];

        if ($packed['expire_at'] > 0) {
            $options['ex'] = max(1, $packed['expire_at'] - time());
        }

        return $this->redis->set($this->cacheKey($key), $packed['payload'], $options) === true;
    }

    public function stats(): array
    {
        $now = time();
        $totalEntries = 0;
        $expiredEntries = 0;

        $this->scanKeys($this->cachePrefixPattern(), function (string $redisKey) use (&$totalEntries, &$expiredEntries, $now) {
            $payload = $this->unpackValue($this->redis->get($redisKey));

            if (!is_array($payload)) {
                return;
            }

            $totalEntries++;
            if (($payload['expire_at'] ?? 0) > 0 && (int) $payload['expire_at'] <= $now) {
                $expiredEntries++;
            }
        });

        $memory = 0;
        $info = $this->redis->info('memory');
        if (is_array($info) && isset($info['used_memory'])) {
            $memory = (int) $info['used_memory'];
        }

        return [
            'total_entries' => $totalEntries,
            'active_entries' => $totalEntries - $expiredEntries,
            'expired_entries' => $expiredEntries,
            'database_size' => $memory,
        ];
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->retrieve($key);
        if ($value !== null) {
            $this->erase([$key]);
            return $value;
        }

        return $default;
    }

    public function storeManyWithExpiry(array $items): void
    {
        foreach ($items as $key => $config) {
            if (!is_array($config)) {
                continue;
            }

            $this->store((string) $key, $config['value'] ?? null, $config['expire'] ?? null);
        }
    }

    public function ttl(string $key): ?int
    {
        if (!$this->has($key)) {
            return null;
        }

        $metadata = $this->metadata($key);
        if ((int) ($metadata['expire_at'] ?? 0) === 0) {
            return null;
        }

        $ttl = (int) $metadata['expire_at'] - time();
        return $ttl > 0 ? $ttl : 0;
    }

    public function optimizeCache(): void
    {
        $this->cleanupExpired();
    }

    public function lock(string $key, string $owner, int $timeout = 10, int $waitTimeout = 5): bool
    {
        $waitTimeout = max(0, $waitTimeout);
        $end = time() + $waitTimeout;

        while (true) {
            $timeout = max(1, $timeout);
            $payload = [
                self::REDIS_OWNER_KEY => $owner,
                self::REDIS_LOCKED_AT_KEY => time(),
                self::REDIS_EXPIRE_AT_KEY => time() + $timeout,
            ];

            if ($this->redis->set($this->lockKey($key), json_encode($payload, JSON_UNESCAPED_UNICODE), ['nx', 'ex' => $timeout])) {
                return true;
            }

            if ($waitTimeout === 0 || time() >= $end) {
                return false;
            }

            usleep(100000);
        }
    }

    public function unlock(string $key, string $owner): bool
    {
        $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current then
    return 0
end
local ok, data = pcall(cjson.decode, current)
if not ok or not data or not data.owner then
    return 0
end
if data.owner == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

        return (int) $this->redis->eval($script, [$this->lockKey($key), $owner], 1) > 0;
    }

    public function unlockAll(string $owner): int
    {
        $deleted = 0;
        $this->scanKeys($this->lockNamespace . ':*', function (string $redisKey) use (&$deleted, $owner) {
            $payload = $this->decodeLockPayload($redisKey);

            if (($payload[self::REDIS_OWNER_KEY] ?? null) === $owner) {
                $deleted += (int) $this->redis->del($redisKey);
            }
        });

        return $deleted;
    }

    public function isLocked(string $key): bool
    {
        $redisKey = $this->lockKey($key);
        $payload = $this->decodeLockPayload($redisKey);

        if ($payload === []) {
            return false;
        }

        $expiresAt = (int) ($payload[self::REDIS_EXPIRE_AT_KEY] ?? 0);
        if ($expiresAt > 0 && $expiresAt <= time()) {
            $this->redis->del($redisKey);
            return false;
        }

        return true;
    }

    public function ownsLock(string $key, string $owner): bool
    {
        $payload = $this->decodeLockPayload($this->lockKey($key));

        return ($payload[self::REDIS_OWNER_KEY] ?? null) === $owner;
    }

    public function releaseExpiredLocks(): int
    {
        $now = time();
        $deleted = 0;

        $this->scanKeys($this->lockNamespace . ':*', function (string $redisKey) use (&$deleted, $now) {
            $payload = $this->decodeLockPayload($redisKey);
            $expiresAt = (int) ($payload[self::REDIS_EXPIRE_AT_KEY] ?? 0);

            if ($expiresAt > 0 && $expiresAt <= $now) {
                $deleted += (int) $this->redis->del($redisKey);
            }
        });

        return $deleted;
    }

    public function extendLock(string $key, string $owner, int $additionalSeconds): bool
    {
        $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current then
    return 0
end
local ok, data = pcall(cjson.decode, current)
if not ok or not data or not data.owner or not data.expire_at then
    return 0
end
if data.owner ~= ARGV[1] then
    return 0
end
local nextExpire = tonumber(data.expire_at) + tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local ttl = nextExpire - now
if ttl <= 0 then
    return 0
end
data.expire_at = nextExpire
redis.call('SET', KEYS[1], cjson.encode(data), 'XX', 'EX', ttl)
return 1
LUA;

        return (int) $this->redis->eval(
            $script,
            [$this->lockKey($key), $owner, (string) max(1, $additionalSeconds), (string) time()],
            1
        ) > 0;
    }

    public function getLockInfo(string $key): ?array
    {
        $payload = $this->decodeLockPayload($this->lockKey($key));

        if ($payload === []) {
            return null;
        }

        return [
            'owner' => $payload[self::REDIS_OWNER_KEY] ?? null,
            'locked_at' => $payload[self::REDIS_LOCKED_AT_KEY] ?? null,
            'expire_at' => $payload[self::REDIS_EXPIRE_AT_KEY] ?? null,
        ];
    }

    public function forceUnlock(string $key): bool
    {
        return (bool) $this->redis->del($this->lockKey($key));
    }

    public function optimizeLocks(): void
    {
        $this->releaseExpiredLocks();
    }

    private function cacheKey(string $key): string
    {
        return $this->cacheKeyPrefix . $key;
    }

    private function lockKey(string $key): string
    {
        return sprintf('%s:%s', $this->lockNamespace, $key);
    }

    private function cachePrefixPattern(): string
    {
        return $this->cacheKeyPrefix . '*';
    }

    private function packValue(string $key, mixed $data, ?string $expire): array
    {
        $expireAt = $this->expireAt($expire);
        $payload = [
            'key' => $key,
            'value' => serialize($data),
            'created_at' => time(),
            'expire_at' => $expireAt,
        ];

        return [
            'payload' => serialize($payload),
            'expire_at' => $expireAt,
        ];
    }

    private function unpackValue(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        $payload = @unserialize($value);
        if (!is_array($payload) || !array_key_exists('value', $payload) || !array_key_exists('created_at', $payload)) {
            return null;
        }

        return [
            'value' => @unserialize((string) $payload['value']),
            'created_at' => (int) ($payload['created_at'] ?? 0),
            'expire_at' => (int) ($payload['expire_at'] ?? 0),
        ];
    }

    private function isExpiredPayload(array $payload): bool
    {
        return (int) ($payload['expire_at'] ?? 0) > 0 && (int) $payload['expire_at'] <= time();
    }

    private function cleanupExpired(): int
    {
        $expiredKeys = [];

        $this->scanKeys($this->cachePrefixPattern(), function (string $redisKey) use (&$expiredKeys) {
            $payload = $this->unpackValue($this->redis->get($redisKey));

            if (!is_array($payload) || $this->isExpiredPayload($payload)) {
                $expiredKeys[] = $redisKey;
            }
        });

        return $expiredKeys === [] ? 0 : (int) $this->redis->del($expiredKeys);
    }

    private function scanKeys(string $pattern, callable $callback): void
    {
        $cursor = 0;
        do {
            $keys = $this->redis->scan($cursor, $pattern);
            if ($keys === false) {
                break;
            }

            foreach ((array) $keys as $key) {
                if (is_string($key)) {
                    $callback($key);
                }
            }
        } while ($cursor > 0);
    }

    private function decodeLockPayload(string $redisKey): array
    {
        $raw = $this->redis->get($redisKey);
        if (!is_string($raw)) {
            return [];
        }

        $payload = @json_decode($raw, true);

        return is_array($payload) ? $payload : [];
    }

    private function expireAt(null|string $expire): int
    {
        if ($expire === null) {
            return 0;
        }

        $expireAt = strtotime($expire);

        return $expireAt === false ? 0 : (int) $expireAt;
    }
}
