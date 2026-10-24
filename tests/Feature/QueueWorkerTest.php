<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Queue\{Job, Queue};

final class QueueWorkerTest extends FrameworkTestCase
{
    private function queue(): Queue
    {
        $this->app->setConfig('queue.default', 'file');
        $this->app->setConfig('queue.connections.file.path', $this->storagePath . '/queue');
        return new Queue();
    }

    public function test_constructor_failures_are_retried_and_do_not_strand_reserved_jobs(): void
    {
        $queue = $this->queue();
        $queue->push(new Job(ThrowingQueueConstructor::class));
        ob_start();
        try {
            $queue->work(once: true, sleep: 0, delay: 0, tries: 2);
            $pending = $queue->getJobs()[0];
            $this->assertSame('pending', $pending->getMetadata('status'));
            $this->assertSame(1, (int) $pending->getMetadata('attempts'));
            $queue->work(once: true, sleep: 0, delay: 0, tries: 2);
            $this->assertCount(1, $queue->getFailedJobs());
            $this->assertSame('failed', $queue->getJobs()[0]->getMetadata('status'));

            $path = $this->storagePath . '/completed';
            $queue->push(new Job('file_put_contents', [$path, 'done']));
            $queue->work(once: true, sleep: 0);
            $this->assertSame('done', file_get_contents($path));
        } finally {
            ob_end_clean();
        }
    }

    public function test_queue_work_command_accepts_zero_lifetime_and_selects_queues(): void
    {
        $queue = $this->queue();
        $path = $this->storagePath . '/command';
        $queue->push(new Job('file_put_contents', [$path, 'done']), 'emails');
        $queue->push(new Job('strlen', ['untouched']), 'other');
        $args = Spark\Console\Prompt::parseArguments(['--once', '--timeout=0', '--sleep=0', '--queue=emails']);
        ob_start();
        try {
            (new Spark\Foundation\Console\PrimaryCommandsHandler())->runQueueJobs($queue, $args);
        } finally {
            ob_end_clean();
        }
        $this->assertSame('done', file_get_contents($path));
        $this->assertCount(1, $queue->getJobs('other'));
        $this->assertCount(0, $queue->getJobs('emails'));
    }

    public function test_invalid_worker_options_are_rejected_before_claiming_jobs(): void
    {
        $queue = $this->queue();
        $queue->push(new Job('strlen', ['pending']));
        foreach (['sleep' => -1, 'timeout' => -1, 'delay' => -1, 'tries' => 0] as $name => $value) {
            $this->assertThrows(InvalidArgumentException::class, fn() => $queue->work(...[$name => $value]));
        }
        $this->assertSame('pending', $queue->getJobs()[0]->getMetadata('status'));
    }
}

final class ThrowingQueueConstructor
{
    public function __construct()
    {
        throw new RuntimeException('Cannot construct job.');
    }
}
