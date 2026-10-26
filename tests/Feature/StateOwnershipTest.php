<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Cache\Lock;
use Spark\Facades\Lock as LockFacade;
use Spark\Foundation\Application;
use Spark\Queue\Job;
use Spark\Queue\PendingDispatch;

final class StateOwnershipTest extends FrameworkTestCase
{
    public function test_manual_facade_lock_survives_until_explicit_release(): void
    {
        $competitor = new Lock();
        $this->assertTrue(LockFacade::lock('invoice', 30, 0));
        try {
            $this->assertTrue($competitor->isLocked('invoice'));
            $this->assertFalse($competitor->lock('invoice', 30, 0));
            $this->assertTrue(LockFacade::ownsLock('invoice'));
        } finally {
            LockFacade::unlock('invoice');
        }
        $this->assertTrue($competitor->lock('invoice', 30, 0));
    }

    public function test_named_helpers_do_not_share_instances_between_applications(): void
    {
        $firstCache = cache('ownership');
        $firstLock = lock(name: 'ownership');
        $firstCache->put('value', 'first');

        $second = Application::create($this->storagePath, [
            'app' => ['key' => str_repeat('b', 32)],
            'cache' => ['driver' => 'file', 'connections' => ['file' => ['path' => $this->storagePath . '/second-cache']]],
        ], providers: []);

        try {
            $this->assertFalse($firstCache === cache('ownership'));
            $this->assertFalse($firstLock === lock(name: 'ownership'));
            $this->assertNull(cache('ownership')->get('value'));
            cache('ownership')->put('value', 'second');
            cache('ownership')->unload();
            $this->assertSame('second', cache('ownership')->get('value'));
        } finally {
            Application::$app = $this->app;
        }

        $this->assertSame($firstCache, cache('ownership'));
        $this->assertSame($firstLock, lock(name: 'ownership'));
        $this->assertSame('first', cache('ownership')->get('value'));
    }

    public function test_lock_helper_accepts_zero_as_a_key(): void
    {
        $this->assertTrue(lock('0', waitTimeout: 0));
        $this->assertTrue(lock()->ownsLock('0'));
        $this->assertTrue(lock()->unlock('0'));
    }

    public function test_url_helper_does_not_reuse_another_application_root(): void
    {
        $this->app->setConfig('app.url', 'https://first.example.test');
        $this->assertSame('https://first.example.test', rtrim((string) url('/'), '/'));
        $this->app->setConfig('app.url', 'https://second.example.test');
        $this->assertSame('https://second.example.test', rtrim((string) url('/'), '/'));
    }

    public function test_failed_explicit_dispatch_is_not_retried_by_destructor(): void
    {
        $job = new class ('strlen') extends Job {
            public int $dispatches = 0;

            public function dispatch(string $queue = 'default'): void
            {
                $this->dispatches++;
                throw new RuntimeException('Transport failed after sending');
            }
        };
        $pending = new PendingDispatch($job);
        $this->assertThrows(RuntimeException::class, fn() => $pending->send());
        try {
            unset($pending);
        } catch (RuntimeException) {
            // Count attempts even when the old destructor repeats the failure.
        }
        $this->assertSame(1, $job->dispatches);
    }
}
