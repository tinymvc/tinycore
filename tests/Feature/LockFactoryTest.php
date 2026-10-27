<?php

require_once dirname(__DIR__) . '/Support/DriverTestCase.php';

use Spark\Cache\Lock;
use Spark\Facades\Lock as LockFacade;

final class LockFactoryTest extends DriverTestCase
{
    public function test_factories_create_independent_owners_while_helpers_remain_shared(): void
    {
        $this->app->setConfig('cache.driver', 'file');
        $this->app->setConfig('cache.connections.file.path', $this->storagePath . '/cache');
        $shared = lock();
        $first = Lock::make();
        $second = Lock::make();
        $this->assertFalse($first === $second);
        $this->assertFalse($first->getLockOwner() === $second->getLockOwner());
        $this->assertFalse($shared === $first);
        $this->assertSame($shared, lock());
        $this->assertSame($shared, LockFacade::make());
        $this->assertSame(lock(name: 'reports'), LockFacade::make('reports'));
        $this->assertSame(lock(name: 'reports'), lock(name: 'reports'));
        $this->assertInstanceOf(DerivedFactoryLock::class, DerivedFactoryLock::make());
        $this->assertInstanceOf(DerivedFactoryLock::class, DerivedFactoryLock::make('reports'));
        $this->assertTrue($first->lock('invoice', 30, 0));
        try {
            $this->assertFalse($second->lock('invoice', 30, 0));
            $this->assertFalse($second->unlock('invoice'));
            $this->assertTrue($first->ownsLock('invoice'));
        } finally {
            $first->unlock('invoice');
        }
    }

    public function test_expired_factory_owner_cannot_release_a_new_file_lease(): void
    {
        $this->verifyExpiredOwner('file');
    }

    public function test_expired_factory_owner_cannot_release_a_new_database_lease(): void
    {
        $this->verifyExpiredOwner('database');
    }

    public function test_expired_factory_owner_cannot_release_a_new_redis_lease(): void
    {
        if (!$this->redisEnabled()) {
            $this->markTestSkipped('Redis integration is not configured.');
        }
        $this->verifyExpiredOwner('redis');
    }

    private function verifyExpiredOwner(string $driver): void
    {
        $this->schema();
        $this->app->setConfig('cache.driver', $driver);
        $this->app->setConfig('cache.connections.file.path', $this->storagePath . '/cache');
        $this->app->setConfig('cache.connections.redis', $this->redisConfig());
        $old = Lock::make('lease');
        $this->assertTrue($old->lock('invoice', 1, 0));
        usleep(1100000);
        $replacement = Lock::make('lease');
        $this->assertTrue($replacement->lock('invoice', 30, 0));
        try {
            $this->assertFalse($old->unlock('invoice'));
            $this->assertSame(0, $old->unlockAll());
            unset($old);
            $this->assertTrue($replacement->ownsLock('invoice'));
        } finally {
            $replacement->unlockAll();
        }
    }
}

final class DerivedFactoryLock extends Lock
{
}
