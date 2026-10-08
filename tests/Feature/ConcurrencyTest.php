<?php

require_once dirname(__DIR__) . '/Support/DriverTestCase.php';

final class ConcurrencyTest extends DriverTestCase
{
    public function test_concurrent_cache_locks_and_job_claims(): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new \Spark\Testing\SkippedTest('pcntl is needed for process concurrency tests.');
        }

        $this->schema();

        $drivers = $this->redisEnabled() ? ['database', 'file', 'redis'] : ['database', 'file'];

        foreach ($drivers as $driver) {
            $config = ['path' => $this->storagePath . '/concurrent-' . $driver];
            $cacheClass = $driver === 'database' ? \Spark\Cache\Storage\DatabaseStorage::class : \Spark\Cache\Storage\FileStorage::class;
            $queueClass = $driver === 'database' ? \Spark\Queue\Storage\DatabaseStorage::class : \Spark\Queue\Storage\FileStorage::class;
            if ($driver === 'redis') {
                $config = $this->redisConfig();
                $cacheClass = \Spark\Cache\Storage\RedisStorage::class;
                $queueClass = \Spark\Queue\Storage\RedisStorage::class;
            }

            $cache = new $cacheClass('concurrent', $config);
            $queue = new $queueClass($config);
            $cache->store('counter', 0);
            $children = [];

            for ($worker = 0; $worker < 4; $worker++) {
                $pid = pcntl_fork();

                if ($pid === 0) {
                    try {
                        app(\Spark\Database\DB::class)->resetPdo();
                        $childCache = new $cacheClass('concurrent', $config);
                        $childQueue = new $queueClass($config);
                        $won = $childCache->lock('winner', 'worker-' . $worker, 60, 0);
                        file_put_contents($this->storagePath . '/' . $driver . '-winner-' . $worker, $won ? '1' : '0');

                        for ($i = 0; $i < 20; $i++) {
                            if ($childCache->increment('counter') === false) {
                                throw new \RuntimeException('Lost increment.');
                            }

                            $childQueue->pushOnce(new \Spark\Queue\Job('strlen', ['same']));
                        }

                        exit(0);
                    } catch (\Throwable $error) {
                        fwrite(STDERR, $error->getMessage() . "\n");
                        exit(1);
                    }
                }

                if ($pid < 0) {
                    throw new \RuntimeException('Unable to fork test worker.');
                }

                $children[] = $pid;
            }

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $this->assertSame(80, $cache->retrieve('counter'));
            $winners = array_sum(array_map('file_get_contents', glob($this->storagePath . '/' . $driver . '-winner-*')));
            $this->assertSame(1, (int) $winners);
            $this->assertCount(1, $queue->getJobs());
            $consumers = [];

            for ($worker = 0; $worker < 4; $worker++) {
                $pid = pcntl_fork();

                if ($pid === 0) {
                    app(\Spark\Database\DB::class)->resetPdo();
                    $childQueue = new $queueClass($config);
                    $claimed = $childQueue->getNextJob();
                    file_put_contents($this->storagePath . '/' . $driver . '-claim-' . $worker, $claimed ? '1' : '0');
                    exit(0);
                }

                $consumers[] = $pid;
            }

            foreach ($consumers as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $claims = array_sum(array_map('file_get_contents', glob($this->storagePath . '/' . $driver . '-claim-*')));
            $this->assertSame(1, (int) $claims);
            $this->assertFalse($queue->getNextJob());
            $queue->clearAllJobs();
            $cache->forceUnlock('winner');
        }
    }

    public function test_expired_owners_and_stale_reservations(): void
    {
        $this->schema();
        $caches = $this->caches();
        $queues = $this->queues();

        foreach ($caches as $cache) {
            $this->assertTrue($cache->lock('expiry', 'old', 1, 0));
        }

        foreach ($queues as $queue) {
            $queue->push(new \Spark\Queue\Job('strlen', ['stale']));
            $this->assertTrue($queue->getNextJob() instanceof \Spark\Queue\Contracts\JobContract);
        }

        sleep(2);

        foreach ($caches as $cache) {
            $this->assertTrue($cache->lock('expiry', 'new', 30, 0));
            $this->assertFalse($cache->unlock('expiry', 'old'));
            $this->assertFalse($cache->extendLock('expiry', 'old', 30));
            $this->assertTrue($cache->ownsLock('expiry', 'new'));
            $cache->unlock('expiry', 'new');
        }

        foreach ($queues as $queue) {
            $this->assertSame(1, $queue->recoverStaleJobs(1));
            $this->assertTrue($queue->getNextJob() instanceof \Spark\Queue\Contracts\JobContract);
            $this->assertFalse($queue->getNextJob());
            $queue->clearAllJobs();
        }
    }
}
