<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class QueueOutputTest extends FrameworkTestCase
{
    public function test_queue_output_is_readable_in_captured_logs(): void
    {
        putenv('NO_COLOR=1');
        $this->app->mergeConfig(['queue' => [
            'driver' => 'file',
            'connections' => ['file' => ['path' => $this->storagePath . '/output-queue']],
        ]]);
        $queue = new \Spark\Queue\Queue();
        $run = function () use ($queue): string {
            ob_start();

            try {
                $queue->work(once: true, sleep: 0, delay: 0, tries: 2, timeout: 1, queue: 'emails');

                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        $listing = function (bool $failed = false) use ($queue): string {
            ob_start();

            try {
                $handler = new \Spark\Foundation\Console\PrimaryCommandsHandler;

                if ($failed) {
                    $handler->listFailedQueueJobs($queue, []);
                } else {
                    $handler->listQueueJobs($queue, []);
                }

                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        $this->assertStringContainsString('No scheduled jobs found.', $listing());
        $this->assertStringContainsString('No failed jobs found.', $listing(true));
        $queue->push(new \Spark\Queue\Job('strlen', ['example']), 'emails');
        $scheduled = $listing();
        $this->assertStringContainsString('Scheduled at', $scheduled);
        $this->assertStringContainsString('emails', $scheduled);
        $this->assertStringContainsString('strlen', $scheduled);
        $success = $run();
        $this->assertStringContainsString('RUNNING', $success);
        $this->assertStringContainsString('DONE', $success);
        $this->assertStringContainsString('queue=emails', $success);
        $this->assertTrue((bool) preg_match('/\d+\.\d{2}ms DONE/', $success));
        $queue->push(new \Spark\Queue\Job('core_queue_output_failure'), 'emails');
        $retry = $run();
        $this->assertStringContainsString('FAIL', $retry);
        $this->assertStringContainsString('RETRY', $retry);
        $this->assertStringContainsString('First line Second line', $retry);
        $this->assertFalse(str_contains($retry, 'hidden title'));
        $failure = $run();
        $this->assertStringContainsString('attempt=2/2', $failure);
        $this->assertStringContainsString('ERROR', $failure);
        $this->assertCount(1, $queue->getFailedJobs());
        $failed = $listing(true);
        $this->assertStringContainsString('Failed at', $failed);
        $this->assertStringContainsString('First line Second line', $failed);
        $this->assertFalse(str_contains($failed, "\033"));
        $this->assertFalse(str_contains($failed, 'hidden title'));
        $queue->clearAllJobs();
        $empty = $run();
        $this->assertFalse(str_contains($empty, 'timeout reached'));

        foreach (explode("\n", trim($success . $retry . $failure . $empty)) as $line) {
            $this->assertTrue((bool) preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $line));
            $this->assertFalse((bool) preg_match('/[\x00-\x1F\x7F]/', $line));
        }
    }
}
