<?php

require_once dirname(__DIR__) . '/Support/DriverTestCase.php';

use Spark\Cache\Storage\RedisStorage;
use Spark\Utils\RedisConnector;

final class RedisLockOwnershipTest extends DriverTestCase
{
    public function test_bulk_release_cannot_delete_a_replacement_owners_lock(): void
    {
        if (!$this->redisEnabled()) {
            $this->markTestSkipped('Redis integration is not configured.');
        }

        $config = $this->redisConfig();
        $store = new RedisStorage('race', $config);
        $this->assertTrue($store->lock('lease', 'first', 30, 0));
        $other = RedisConnector::make($config, 'replacement');
        $key = $config['prefix'] . ':lock:' . md5('race') . ':lease';
        $interleaving = new LockInterleavingRedis;
        $interleaving->connect($config['socket'] ?: $config['host'], $config['socket'] ? 0 : $config['port']);
        if ($config['password']) {
            $interleaving->auth($config['password']);
        }
        $interleaving->replaceOwner = static function () use ($other, $key): void {
            $other->setex($key, 30, json_encode(['owner' => 'second', 'expire_at' => time() + 30]));
        };
        (new ReflectionProperty($store, 'redis'))->setValue($store, $interleaving);

        $this->assertSame(0, $store->unlockAll('first'));
        $this->assertTrue($store->ownsLock('lease', 'second'));
        $this->assertSame(1, $store->unlockAll('second'));
    }
}

if (class_exists(Redis::class)) {
    /** Force a lease takeover between a client-side read and delete, or just before an atomic release. */
    final class LockInterleavingRedis extends Redis
    {
        public ?Closure $replaceOwner = null;

        private function interleave(): void
        {
            $callback = $this->replaceOwner;
            $this->replaceOwner = null;
            $callback && $callback();
        }

        public function get(string $key): mixed
        {
            $value = parent::get($key);
            $this->interleave();
            return $value;
        }

        public function eval(string $script, array $args = [], int $num_keys = 0): mixed
        {
            $this->interleave();
            return parent::eval($script, $args, $num_keys);
        }
    }
}
