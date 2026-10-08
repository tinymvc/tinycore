<?php

require_once __DIR__ . '/DriverTestCase.php';

abstract class StorageDriverContract extends DriverTestCase
{
    protected string $driver;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->driver === 'redis' && !$this->redisEnabled()) {
            $this->markTestSkipped('Redis integration is not configured.');
        }
        $this->schema();
    }

    private function cacheStore(): \Spark\Cache\Contracts\CacheStorageContract
    {
        return match ($this->driver) {
            'file' => new \Spark\Cache\Storage\FileStorage('contract', ['path' => $this->storagePath . '/cache']),
            'database' => new \Spark\Cache\Storage\DatabaseStorage('contract'),
            'redis' => new \Spark\Cache\Storage\RedisStorage('contract', $this->redisConfig()),
        };
    }

    private function queueStore(): \Spark\Queue\Contracts\QueueStorageContract
    {
        return match ($this->driver) {
            'file' => new \Spark\Queue\Storage\FileStorage(['path' => $this->storagePath . '/queue']),
            'database' => new \Spark\Queue\Storage\DatabaseStorage(),
            'redis' => new \Spark\Queue\Storage\RedisStorage($this->redisConfig()),
        };
    }

    private function job(string $value): \Spark\Queue\Job
    {
        return new \Spark\Queue\Job('strlen', [$value], now()->subSeconds(10));
    }

    public function test_cache_empty_string_roundtrip(): void
    {
        $cache = $this->cacheStore();
        $cache->store('value', '');
        $this->assertTrue($cache->has('value'));
        $this->assertSame('', $cache->retrieve('value'));
        $this->assertSame('', $cache->pull('value'));
        $this->assertFalse($cache->has('value'));
    }

    public function test_cache_unicode_roundtrip(): void
    {
        $cache = $this->cacheStore();
        $cache->store('value', 'বাংলা');
        $this->assertTrue($cache->has('value'));
        $this->assertSame('বাংলা', $cache->retrieve('value'));
        $this->assertSame('বাংলা', $cache->pull('value'));
        $this->assertFalse($cache->has('value'));
    }

    public function test_cache_nested_roundtrip(): void
    {
        $cache = $this->cacheStore();
        $cache->store('value', ['a' => [null, false, 0]]);
        $this->assertTrue($cache->has('value'));
        $this->assertSame(['a' => [null, false, 0]], $cache->retrieve('value'));
        $this->assertSame(['a' => [null, false, 0]], $cache->pull('value'));
        $this->assertFalse($cache->has('value'));
    }

    public function test_cache_negative_roundtrip(): void
    {
        $cache = $this->cacheStore();
        $cache->store('value', -9);
        $this->assertTrue($cache->has('value'));
        $this->assertSame(-9, $cache->retrieve('value'));
        $this->assertSame(-9, $cache->pull('value'));
        $this->assertFalse($cache->has('value'));
    }

    public function test_cache_float_roundtrip(): void
    {
        $cache = $this->cacheStore();
        $cache->store('value', 1.25);
        $this->assertTrue($cache->has('value'));
        $this->assertSame(1.25, $cache->retrieve('value'));
        $this->assertSame(1.25, $cache->pull('value'));
        $this->assertFalse($cache->has('value'));
    }

    public function test_cache_overwrite_removes_old_expiration(): void
    {
        $c = $this->cacheStore();
        $c->store('key', 'old', '-1 minute');
        $c->store('key', 'fresh');
        $this->assertSame('fresh', $c->retrieve('key'));
        $this->assertNull($c->ttl('key'));
    }

    public function test_cache_overwrite_changes_type(): void
    {
        $c = $this->cacheStore();
        $c->store('key', ['old']);
        $c->store('key', false);
        $this->assertFalse($c->retrieve('key'));
        $this->assertTrue($c->has('key'));
    }

    public function test_cache_add_null_prevents_replacement(): void
    {
        $c = $this->cacheStore();
        $this->assertTrue($c->add('key', null));
        $this->assertFalse($c->add('key', 'replacement'));
        $this->assertNull($c->retrieve('key'));
    }

    public function test_cache_add_false_prevents_replacement(): void
    {
        $c = $this->cacheStore();
        $this->assertTrue($c->add('key', false));
        $this->assertFalse($c->add('key', 'replacement'));
        $this->assertFalse($c->retrieve('key'));
    }

    public function test_cache_pull_missing_preserves_default_type(): void
    {
        $c = $this->cacheStore();
        $this->assertSame(['default'], $c->pull('missing', ['default']));
        $this->assertFalse($c->has('missing'));
    }

    public function test_cache_erase_selected_keys_only(): void
    {
        $c = $this->cacheStore();
        $c->storeMany(['a' => 1, 'b' => 2, 'c' => 3]);
        $c->erase(['a', 'c', 'missing']);
        $this->assertSame(['b' => 2], $c->retrieveAll());
        $this->assertNull($c->retrieve('a'));
    }

    public function test_cache_empty_batch_is_noop(): void
    {
        $c = $this->cacheStore();
        $c->store('keep', 1);
        $c->storeMany([]);
        $c->erase([]);
        $this->assertSame(['keep' => 1], $c->retrieveAll());
        $this->assertSame([], $c->retrieve([]));
    }

    public function test_cache_expired_counter_is_not_resurrected(): void
    {
        $c = $this->cacheStore();
        $c->store('counter', 2, '-1 minute');
        $this->assertFalse($c->increment('counter'));
        $this->assertFalse($c->has('counter'));
    }

    public function test_cache_increment_rejects_nonnumeric(): void
    {
        $c = $this->cacheStore();
        $c->store('counter', 'abc');
        $this->assertFalse($c->increment('counter'));
        $this->assertSame('abc', $c->retrieve('counter'));
    }

    public function test_cache_zero_increment_keeps_value(): void
    {
        $c = $this->cacheStore();
        $c->store('counter', 3);
        $this->assertSame(3, $c->increment('counter', 0));
        $this->assertSame(3, $c->retrieve('counter'));
    }

    public function test_cache_negative_increment(): void
    {
        $c = $this->cacheStore();
        $c->store('counter', 1);
        $this->assertSame(-2, $c->increment('counter', -3));
        $this->assertSame(-2, $c->retrieve('counter'));
    }

    public function test_cache_expired_pull_returns_default(): void
    {
        $c = $this->cacheStore();
        $c->store('key', 1, '-1 minute');
        $this->assertSame('default', $c->pull('key', 'default'));
        $this->assertFalse($c->has('key'));
    }

    public function test_cache_mixed_expiry_batch(): void
    {
        $c = $this->cacheStore();
        $c->storeManyWithExpiry(['old' => ['value' => 1, 'expire' => '-1 minute'], 'new' => ['value' => 2, 'expire' => '+5 minutes']]);
        $this->assertFalse($c->has('old'));
        $this->assertSame(2, $c->retrieve('new'));
        $this->assertTrue($c->ttl('new') > 0);
    }

    public function test_lock_force_unlock_allows_new_owner(): void
    {
        $c = $this->cacheStore();
        $this->assertTrue($c->lock('key', 'a', 60, 0));
        $this->assertTrue($c->forceUnlock('key'));
        $this->assertTrue($c->lock('key', 'b', 60, 0));
        $this->assertTrue($c->ownsLock('key', 'b'));
    }

    public function test_lock_unlock_all_is_owner_scoped(): void
    {
        $c = $this->cacheStore();
        $c->lock('a', 'owner1', 60, 0);
        $c->lock('b', 'owner1', 60, 0);
        $c->lock('c', 'owner2', 60, 0);
        $this->assertSame(2, $c->unlockAll('owner1'));
        $this->assertFalse($c->isLocked('a'));
        $this->assertTrue($c->ownsLock('c', 'owner2'));
    }

    public function test_lock_cache_key_and_lock_key_coexist(): void
    {
        $c = $this->cacheStore();
        $c->store('same', 'cached');
        $this->assertTrue($c->lock('same', 'owner', 60, 0));
        $c->erase(['same']);
        $this->assertTrue($c->ownsLock('same', 'owner'));
        $this->assertNull($c->retrieve('same'));
    }

    public function test_lock_wrong_owner_extend_preserves_owner(): void
    {
        $c = $this->cacheStore();
        $c->lock('key', 'owner', 60, 0);
        $this->assertFalse($c->extendLock('key', 'wrong', 60));
        $this->assertSame('owner', $c->getLockInfo('key')['owner']);
    }

    public function test_lock_missing_unlock_is_false(): void
    {
        $c = $this->cacheStore();
        $this->assertFalse($c->unlock('missing', 'owner'));
        $this->assertNull($c->getLockInfo('missing'));
    }

    public function test_lock_distinct_keys_can_be_locked(): void
    {
        $c = $this->cacheStore();
        $this->assertTrue($c->lock('a', 'owner', 60, 0));
        $this->assertTrue($c->lock('b', 'owner', 60, 0));
        $this->assertSame(2, $c->unlockAll('owner'));
    }

    public function test_queue_empty_queue_returns_false(): void
    {
        $q = $this->queueStore();
        $this->assertFalse($q->getNextJob());
        $this->assertSame([], $q->getJobs());
    }

    public function test_queue_named_queue_claim_is_isolated(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('mail'), 'mail');
        $this->assertFalse($q->getNextJob('other'));
        $this->assertSame(['mail'], $q->getNextJob('mail')->getParameters());
    }

    public function test_queue_multiple_queue_filter(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('a'), 'a');
        $q->push($this->job('b'), 'b');
        $q->push($this->job('c'), 'c');
        $this->assertCount(2, $q->getJobs(['a', 'c']));
        $this->assertSame(['b'], $q->getNextJob(['b'])->getParameters());
    }

    public function test_queue_remove_queue_preserves_neighbors(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('a'), 'a');
        $q->push($this->job('b'), 'b');
        $q->removeQueue('a');
        $this->assertCount(1, $q->getJobs());
        $this->assertSame(['b'], $q->getNextJob('b')->getParameters());
    }

    public function test_queue_push_once_different_parameters_are_distinct(): void
    {
        $q = $this->queueStore();
        $q->pushOnce($this->job('a'));
        $q->pushOnce($this->job('b'));
        $this->assertCount(2, $q->getJobs());
        $this->assertNotSame($q->getJobs()[0]->getId(), $q->getJobs()[1]->getId());
    }

    public function test_queue_push_once_is_queue_scoped(): void
    {
        $q = $this->queueStore();
        $job = $this->job('same');
        $q->pushOnce($job, 'a');
        $q->pushOnce($job, 'b');
        $this->assertCount(2, $q->getJobs());
        $this->assertCount(1, $q->getJobs('a'));
    }

    public function test_queue_clear_repeated_preserves_ordinary(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('repeat')->repeatDaily());
        $q->push($this->job('ordinary'));
        $q->clearRepeatedJobs();
        $this->assertCount(1, $q->getJobs());
        $this->assertSame(['ordinary'], $q->getNextJob()->getParameters());
    }

    public function test_queue_clear_failed_preserves_pending(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('failed'));
        $id = (int) $q->getNextJob()->getId();
        $q->markJobAsFailed($id, new RuntimeException('failure'), 1);
        $q->push($this->job('pending'));
        $q->clearFailedJobs();
        $this->assertSame([], $q->getFailedJobs());
        $this->assertSame(['pending'], $q->getNextJob()->getParameters());
    }

    public function test_queue_future_job_is_not_claimed(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('future')->schedule(now()->addHours(1)));
        $this->assertCount(1, $q->getJobs());
        $this->assertFalse($q->getNextJob());
    }

    public function test_queue_reserved_job_cannot_be_claimed_twice(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('once'));
        $this->assertSame(['once'], $q->getNextJob()->getParameters());
        $this->assertFalse($q->getNextJob());
    }

    public function test_queue_fresh_reservation_is_not_stale(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('reserved'));
        $q->getNextJob();
        $this->assertSame(0, $q->recoverStaleJobs(3600));
        $this->assertFalse($q->getNextJob());
    }

    public function test_queue_failed_job_preserves_exception_and_attempts(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('failed'));
        $id = (int) $q->getNextJob()->getId();
        $q->markJobAsFailed($id, new RuntimeException('specific failure'), 3);
        $failed = $q->getFailedJobs();
        $this->assertCount(1, $failed);
        $this->assertSame(3, $failed[0]->attempts());
        $this->assertStringContainsString('specific failure', $failed[0]->getReasonFailed());
    }

    public function test_queue_clear_all_allows_subsequent_push(): void
    {
        $q = $this->queueStore();
        $q->push($this->job('old'));
        $q->clearAllJobs();
        $this->assertSame([], $q->getJobs());
        $q->push($this->job('new'));
        $this->assertSame(['new'], $q->getNextJob()->getParameters());
    }

}
