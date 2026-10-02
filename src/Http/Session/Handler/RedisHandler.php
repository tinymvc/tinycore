<?php

namespace Spark\Http\Session\Handler;

use SessionHandlerInterface;
use Spark\Utils\RedisConnector;
use function max;
use function trim;

/**
 * Class RedisHandler
 *
 * Stores session data in Redis via the shared RedisConnector, relying on Redis TTL
 * (set on every write) for expiration instead of a manual gc sweep.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class RedisHandler implements SessionHandlerInterface
{
    private \Redis $redis;

    private string $prefix;

    private int $lifetime;

    public function __construct(private array $config = [])
    {
        $this->lifetime = (int) ($config['lifetime'] ?? 120) * 60;
    }

    public function open(string $path, string $name): bool
    {
        try {
            $redisConfig = RedisConnector::resolveConnectionConfig($this->config);
            $prefix = trim((string) ($redisConfig['prefix'] ?? 'spark'), ':');

            $this->redis = RedisConnector::make($redisConfig, 'session');
            $this->prefix = ($prefix === '' ? 'spark' : $prefix) . ':session:';
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to connect to Redis: ' . $e->getMessage());
        }

        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->redis->get($this->key($id));

        return $data === false ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        return (bool) $this->redis->setex($this->key($id), max($this->lifetime, 60), $data);
    }

    public function destroy(string $id): bool
    {
        $this->redis->del($this->key($id));

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis expires keys on its own via the TTL set in write(); nothing to sweep.
        return 0;
    }

    private function key(string $id): string
    {
        return $this->prefix . $id;
    }
}