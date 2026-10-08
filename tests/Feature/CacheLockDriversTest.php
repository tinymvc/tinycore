<?php

require_once dirname(__DIR__) . '/Support/DriverTestCase.php';

final class CacheLockDriversTest extends DriverTestCase
{
    public function test_cache_clear_preserves_locks_and_key_generation(): void
    {
        $this->schema();
        $cache = new \Spark\Cache\Cache();
        $lock = new \Spark\Cache\Lock();
        $cache->store('value', 'cached');
        $this->assertTrue($lock->lock('running', 60, 0));
        $commands = new \Spark\Foundation\Console\PrimaryCommandsHandler();
        ob_start();

        try {
            $commands->clearCache();
            $this->assertFalse($cache->has('value'));
            $this->assertTrue($lock->isLocked('running'));
            $env = $this->storagePath . '/.env';
            file_put_contents($env, "APP_NAME=Test\nAPP_KEY=\n");
            $commands->generateAppKey();
            $first = file_get_contents($env);
            $commands->generateAppKey();
            $this->assertSame($first, file_get_contents($env));
            $this->assertTrue((bool) preg_match('/APP_KEY=[a-f0-9]{32}/', $first));
        } finally {
            ob_end_clean();
        }
    }

    public function test_cache_values_expiration_and_locks(): void
    {
        $this->schema();

        foreach ($this->caches() as $cache) {
            foreach (['null' => null, 'false' => false, 'zero' => 0, 'binary' => "a\0\xff", 'array' => ['x' => 2]] as $key => $value) {
                $cache->store($key, $value);
                $this->assertTrue($cache->has($key));
                $this->assertSame($value, $cache->retrieve($key));
                $this->assertSame($value, $cache->pull($key, 'missing'));
                $this->assertFalse($cache->has($key));
            }

            $cache->store('expired', 'old', '-1 minute');
            $this->assertFalse($cache->has('expired'));
            $this->assertTrue($cache->add('expired', 'new', '+1 minute'));
            $this->assertFalse($cache->add('expired', 'duplicate'));
            $this->assertSame('new', $cache->retrieve('expired'));
            $this->assertTrue($cache->ttl('expired') > 0);
            $cache->store('counter', 1, '+1 minute');
            $this->assertSame(3, $cache->increment('counter', 2));
            $this->assertFalse($cache->increment('missing'));
            $cache->storeMany(['Foo' => 1, 'foo' => 2, 'numeric' => '3']);
            $this->assertSame(1, $cache->retrieveAll()['Foo']);
            $this->assertSame(['Foo' => 1, 'foo' => 2], $cache->retrieve(['Foo', 'foo', 'absent']));
            $this->assertSame(4, $cache->increment('numeric'));
            $cache->storeManyWithExpiry(['short' => ['value' => 'gone', 'expire' => '-1 second']]);
            $this->assertFalse($cache->has('short'));
            $cache->eraseExpired();
            $this->assertSame([], $cache->getExpired());

            $this->assertTrue($cache->lock('lock', 'owner-a', 20, 0));
            $this->assertFalse($cache->lock('lock', 'owner-b', 20, 0));
            $this->assertFalse($cache->unlock('lock', 'owner-b'));
            $this->assertFalse($cache->unlock('lock', 'owner-a '));
            $this->assertFalse($cache->extendLock('lock', 'owner-a ', 5));
            $this->assertTrue($cache->ownsLock('lock', 'owner-a'));
            $this->assertTrue($cache->extendLock('lock', 'owner-a', 5));
            $this->assertSame('owner-a', $cache->getLockInfo('lock')['owner']);
            $cache->flush();
            $this->assertFalse($cache->has('counter'));
            $this->assertTrue($cache->isLocked('lock'));
            $this->assertTrue($cache->unlock('lock', 'owner-a'));
            $this->assertSame([], $cache->retrieveAll());
        }

        $first = new \Spark\Cache\Storage\DatabaseStorage('a:b');
        $second = new \Spark\Cache\Storage\DatabaseStorage('a');
        $first->store('c', 1);
        $second->store('b:c', 2);
        $this->assertSame(1, $first->retrieve('c'));
        $this->assertSame(2, $second->retrieve('b:c'));
        $first->flush();
        $this->assertSame(2, $second->retrieve('b:c'));
    }

    public function test_redis_namespace_patterns_are_literal(): void
    {
        if (! $this->redisEnabled()) {
            $this->markTestSkipped('Set SPARK_TEST_REDIS_HOST or SPARK_TEST_REDIS_SOCKET to test Redis.');
        }

        $config = $this->redisConfig();
        $literal = new \Spark\Cache\Storage\RedisStorage('test', [
            ...$config,
            'prefix' => $config['prefix'] . '*',
        ]);
        $neighbor = new \Spark\Cache\Storage\RedisStorage('test', [
            ...$config,
            'prefix' => $config['prefix'] . 'other',
        ]);
        $literal->store('value', 1);
        $neighbor->store('value', 2);
        $this->assertSame(['value' => 1], $literal->retrieveAll());
        $literal->flush();
        $this->assertSame(2, $neighbor->retrieve('value'));
        $neighbor->flush();
    }

    public function test_persistent_redis_connections_keep_database_isolation(): void
    {
        if (! $this->redisEnabled()) {
            $this->markTestSkipped('Set SPARK_TEST_REDIS_HOST or SPARK_TEST_REDIS_SOCKET to test Redis.');
        }

        $config = [...$this->redisConfig(), 'persistent' => true, 'persistent_id' => 'release-check'];
        $first = \Spark\Utils\RedisConnector::make([...$config, 'database' => 0], 'same-name');
        $second = \Spark\Utils\RedisConnector::make([...$config, 'database' => 1], 'same-name');
        $key = $config['prefix'] . ':isolation';

        try {
            $first->set($key, 'first');
            $second->set($key, 'second');
            $this->assertSame('first', $first->get($key));
            $this->assertSame('second', $second->get($key));
        } finally {
            $first->del($key);
            $second->del($key);
        }
    }
}
