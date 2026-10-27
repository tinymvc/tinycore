<?php

require_once dirname(__DIR__) . '/Support/DriverTestCase.php';

use Spark\Cache\Cache;
use Spark\Cache\Exceptions\LockTimeoutException;

final class CacheBoundaryTest extends DriverTestCase
{
    private function eachDriver(callable $test): void
    {
        $this->schema();
        foreach ($this->redisEnabled() ? ['file', 'database', 'redis'] : ['file', 'database'] as $driver) {
            config(['cache' => ['driver' => $driver, 'connections' => [$driver => $driver === 'redis' ? $this->redisConfig() : ['path' => $this->storagePath . '/cache']]]]);
            $test(new Cache('boundary'));
        }
    }

    public function test_remember_caches_null_and_does_not_run_callback_twice(): void
    {
        $this->eachDriver(function (Cache $cache) {
            $calls = 0;
            $callback = function ($repository) use (&$calls, $cache) {
                $this->assertSame($cache, $repository);
                $calls++;
                return null;
            };
            $this->assertNull($cache->remember('null', $callback));
            $this->assertNull($cache->remember('null', $callback));
            $this->assertSame(1, $calls);
        });
    }

    public function test_callback_failure_is_not_cached(): void
    {
        $this->eachDriver(function (Cache $cache) {
            $this->assertThrows(RuntimeException::class, fn() => $cache->load('failed', fn() => throw new RuntimeException('failed')));
            $this->assertFalse($cache->has('failed'));
            $this->assertSame('recovered', $cache->load('failed', fn() => 'recovered'));
        });
    }

    public function test_array_access_and_conditional_flush(): void
    {
        $this->eachDriver(function (Cache $cache) {
            $cache['key'] = false;
            $this->assertTrue(isset($cache['key']));
            $this->assertFalse($cache['key']);
            $cache->flushIf(false);
            $this->assertTrue($cache->has('key'));
            unset($cache['key']);
            $this->assertFalse(isset($cache['key']));
            $cache->storeMany(['a' => 1, 'b' => 2])->flushIf(true);
            $this->assertSame([], $cache->retrieveAll());
        });
    }

    public function test_counter_decrement_preserves_expiration(): void
    {
        $this->eachDriver(function (Cache $cache) {
            $cache->store('counter', 0, '+1 minute');
            $this->assertSame(-2, $cache->decrement('counter', 2));
            $this->assertTrue($cache->ttl('counter') > 0);
            $this->assertTrue($cache->ttl('counter') <= 60);
        });
    }

    public function test_lock_callback_releases_after_success_and_error(): void
    {
        $this->eachDriver(function (Cache $cache) {
            $lock = $cache->lock('critical');
            $this->assertSame(42, $lock->block(0, function () use ($lock) {
                $this->assertTrue($lock->isOwnedByCurrentProcess());
                return 42;
            }));
            $this->assertFalse($lock->isLocked());
            $this->assertThrows(Error::class, fn() => $lock->block(0, fn() => throw new Error('failed')));
            $this->assertFalse($lock->isLocked());
        });
    }

    public function test_lock_contention_does_not_execute_callback_or_release_owner(): void
    {
        $this->eachDriver(function (Cache $cache) {
            $lock = $cache->lock('critical', 60);
            $other = $cache->lock('critical', 60);
            $this->assertTrue($lock->get());
            $this->assertThrows(LockTimeoutException::class, fn() => $other->block(0, fn() => $this->fail('Contended callback executed')));
            $this->assertTrue($lock->isOwnedByCurrentProcess());
            $this->assertFalse($other->release());
            $this->assertTrue($lock->release());
        });
    }
}
