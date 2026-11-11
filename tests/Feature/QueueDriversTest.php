<?php

require_once dirname(__DIR__) . '/Support/DriverTestCase.php';

final class QueueDriversTest extends DriverTestCase
{
    public function test_worker_success_failure_and_transaction_rollback(): void
    {
        $this->schema();
        $drivers = $this->redisEnabled() ? ['database', 'file', 'redis'] : ['database', 'file'];

        foreach ($drivers as $driver) {
            $connection = $driver === 'redis' ? $this->redisConfig() : ['path' => $this->storagePath . '/worker'];
            $this->app->mergeConfig(['queue' => ['driver' => $driver, 'connections' => [$driver => $connection]]]);
            $queue = new \Spark\Queue\Queue();
            $output = $this->storagePath . '/worker-' . $driver;
            $queue->push(new \Spark\Queue\Job('file_put_contents', ['filename' => $output, 'data' => 'done']), 'worker');
            ob_start();

            try {
                $queue->work(once: true, sleep: 0, queue: 'worker');
                $this->assertSame('done', file_get_contents($output));
                $this->assertCount(0, $queue->getJobs());
                $queue->push(new \Spark\Queue\Job(DriverFixtureJob::class, [$output]), 'worker');
                $queue->work(once: true, sleep: 0, queue: 'worker');
                $this->assertSame('7', file_get_contents($output));
                $queue->push(new \Spark\Queue\Job('json_decode', ['json' => '{', 'associative' => true, 'depth' => 512, 'flags' => JSON_THROW_ON_ERROR]), 'worker');
                $queue->work(once: true, sleep: 0, tries: 1, queue: 'worker');
                $this->assertCount(1, $queue->getFailedJobs());
                $queue->clearAllJobs();
            } catch (\Throwable $error) {
                fwrite(STDERR, ob_get_contents());

                throw $error;
            } finally {
                ob_end_clean();
            }
        }

        $queue = new \Spark\Queue\Storage\DatabaseStorage();

        try {
            app(\Spark\Database\DB::class)->transaction(function () use ($queue): never {
                $queue->push(new \Spark\Queue\Job('strlen', ['rollback']));

                throw new \RuntimeException('Rollback business operation.');
            });
        } catch (\RuntimeException) {
        }

        $this->assertCount(0, $queue->getJobs());
    }

    public function test_queue_schedules_preserve_absolute_time(): void
    {
        $this->schema();
        $past = new \Spark\Carbon(time() - 60, 'Asia/Dhaka');
        $future = new \Spark\Carbon(time() + 3600, 'America/New_York');

        foreach ($this->queues() as $queue) {
            $queue->push(new \Spark\Queue\Job('strlen', ['due'], $past));
            $queue->push(new \Spark\Queue\Job('strlen', ['later'], $future));
            $due = $queue->getNextJob();
            $this->assertTrue($due instanceof \Spark\Queue\Contracts\JobContract);
            $this->assertSame($past->timestamp, $due->getScheduledTime()->timestamp);
            $this->assertFalse($queue->getNextJob());
            $queue->retryJob($due->getMetadata()['id'], $future, 1);
            $this->assertFalse($queue->getNextJob());
            $queue->rescheduleJob($due->getMetadata()['id'], $past);
            $this->assertSame($past->timestamp, $queue->getNextJob()->getScheduledTime()->timestamp);
            $queue->clearAllJobs();
        }
    }

    public function test_queue_lifecycle(): void
    {
        $this->schema();

        foreach ($this->queues() as $queue) {
            $job = new \Spark\Queue\Job('strlen', ['one'], now()->subSeconds(2));
            $queue->pushOnce($job, 'emails');
            $insertedId = $job->getId();
            $this->assertFalse($queue->pushOnce($job, 'emails'));
            $this->assertSame($insertedId, $job->getId());
            $queue->push(new \Spark\Queue\Job('strlen', ['later'], now()->addHours(1)), 'emails');
            $this->assertCount(2, $queue->getJobs());
            $reserved = $queue->getNextJob('emails');
            $this->assertSame(['one'], $reserved->getParameters());
            $id = (string) $reserved->getMetadata()['id'];
            $this->assertFalse($queue->getNextJob('emails'));
            $queue->updateJobStatus($id, 'processing', 1);
            $queue->retryJob($id, now()->subSeconds(1), 1);
            $this->assertSame(1, (int) $queue->getNextJob('emails')->getMetadata()['attempts']);
            $queue->markJobAsFailed($id, new \RuntimeException('Failure'), 2);
            $queue->markJobAsFailed($id, new \RuntimeException('Failure again'), 2);
            $this->assertCount(1, $queue->getFailedJobs());
            $queue->retryFailedJobs();
            $this->assertCount(0, $queue->getFailedJobs());
            $this->assertSame($id, (string) $queue->getNextJob('emails')->getMetadata()['id']);
            $queue->rescheduleJob($id, now()->addHours(1));
            $this->assertFalse($queue->getNextJob('emails'));
            $this->assertTrue($queue->removeJobById($id));
            $this->assertTrue($queue->removeQueue('emails'));
            $this->assertCount(0, $queue->getJobs());

            $queue->push($job);
            $queue->push($job);
            $duplicates = $queue->getJobs();
            $queue->removeJobById((string) end($duplicates)->getMetadata()['id']);
            $queue->pushOnce($job);
            $this->assertCount(1, $queue->getJobs());
            $queue->clearAllJobs();

            $queue->push(new \Spark\Queue\Job('strlen', [str_repeat('x', 70000)]));
            $this->assertSame(70000, strlen($queue->getNextJob()->getParameters()[0]));
            $queue->clearAllJobs();
        }

    }

    public function test_database_bulk_removal_deletes_only_matching_failures(): void
    {
        $this->schema();
        $queue = new \Spark\Queue\Storage\DatabaseStorage();
        $repeated = (new \Spark\Queue\Job('strlen', ['repeated']))->repeatEveryMinutes(5);
        $email = new \Spark\Queue\Job('strlen', ['email']);
        $other = new \Spark\Queue\Job('strlen', ['other']);
        $queue->push($repeated, 'emails');
        $queue->push($email, 'emails');
        $queue->push($other, 'other');

        foreach ([$repeated, $email, $other] as $job) {
            $queue->markJobAsFailed($job->getId(), new \RuntimeException('Failure'), 1);
        }

        $queue->clearRepeatedJobs();
        $this->assertSame(2, query('failed_jobs')->count());
        $this->assertSame(0, query('failed_jobs')->where('job_id', $repeated->getId())->count());
        $this->assertTrue($queue->removeQueue('emails'));
        $this->assertSame(1, query('failed_jobs')->count());
        $this->assertSame($other->getId(), query('failed_jobs')->value('job_id'));
        $this->assertCount(1, $queue->getJobs());
        $this->assertFalse($queue->removeQueue('emails'));
    }

    public function test_named_queue_transaction_uses_its_connection(): void
    {
        $this->schema();
        $named = $this->storagePath . '/named.db';
        copy($this->storagePath . '/database.db', $named);
        $this->app->mergeConfig(['database' => ['connections' => ['queue_test' => ['driver' => 'sqlite', 'file' => $named]]]]);
        $queue = new \Spark\Queue\Storage\DatabaseStorage(['connection' => 'queue_test']);
        $queue->push(new \Spark\Queue\Job('strlen', ['value']));
        $pdo = \Spark\Database\DB::connection('queue_test')->getPdo();
        $pdo->exec("CREATE TRIGGER reject_failure BEFORE INSERT ON failed_jobs BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        $id = (string) $queue->getJobs()[0]->getMetadata()['id'];

        try {
            $queue->markJobAsFailed($id, new \RuntimeException('test'), 1);
        } catch (\RuntimeException) {
        }

        $this->assertSame('pending', $queue->getJobs()[0]->getMetadata()['status']);
        $this->assertCount(0, $queue->getFailedJobs());
        $this->assertFalse($pdo->inTransaction());
    }
}
