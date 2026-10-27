<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class QueueOutputTest extends FrameworkTestCase
{
    private array $arguments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arguments = $GLOBALS['argv'];
        $GLOBALS['argv'] = ['spark', '--no-ansi'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['argv'] = $this->arguments;
        parent::tearDown();
    }

    public function test_queue_output_is_readable_in_captured_logs(): void
    {
        putenv('NO_COLOR=1');
        putenv('COLUMNS=100');
        $this->app->mergeConfig([
            'queue' => [
                'driver' => 'file',
                'connections' => ['file' => ['path' => $this->storagePath . '/output-queue']],
            ]
        ]);
        $queue = new \Spark\Queue\Queue();
        $run = function (string|array $queues = 'emails') use ($queue): string {
            ob_start();

            try {
                $queue->work(once: true, sleep: 0, delay: 0, tries: 2, timeout: 1, queue: $queues);

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
                    $handler->listFailedQueueJobs(app(Spark\Queue\QueueManager::class), []);
                } else {
                    $handler->listQueueJobs(app(Spark\Queue\QueueManager::class), []);
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
        $this->assertTrue((bool) preg_match('/strlen  #\d+ · 1\/2 /', $success));
        $this->assertTrue((bool) preg_match('/\d+\.\d{2}ms DONE/', $success));
        $this->assertTrue((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}  INFO  Processing jobs from \[emails\]\./m', $success));
        $this->assertTrue((bool) preg_match('/\.{2,} RUNNING$/m', $success));
        $this->assertStringContainsString('Processing jobs from [emails].' . PHP_EOL . PHP_EOL, $success);
        $this->assertStringContainsString('Stopped · 1 completed · 0 failed attempts', $success);
        foreach (explode(PHP_EOL, $success) as $line) {
            if (preg_match('/ (RUNNING|DONE)$/', $line)) {
                $this->assertSame(100, preg_match_all('/./us', $line));
            }
        }
        $queue->push(new \Spark\Queue\Job('core_queue_output_failure'), 'emails');
        $retry = $run();
        $this->assertStringContainsString('FAIL', $retry);
        $this->assertStringContainsString('↳ Retry at:', $retry);
        $this->assertStringContainsString('First line Second line', $retry);
        $this->assertTrue((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}  ERROR  .*First line Second line$/m', $retry));
        $this->assertTrue((bool) preg_match('/^ {21}↳ Retry at: \d{4}-\d{2}-\d{2} /m', $retry));
        $this->assertStringContainsString('Stopped · 0 completed · 1 failed attempt', $retry);
        $this->assertFalse((bool) preg_match('/First line.*FAIL$/m', $retry));
        $this->assertFalse(str_contains($retry, 'hidden title'));
        $failure = $run();
        $this->assertStringContainsString('· 2/2', $failure);
        $this->assertStringContainsString('ERROR', $failure);
        $this->assertCount(1, $queue->getFailedJobs());
        $failed = $listing(true);
        $this->assertStringContainsString('Failed at', $failed);
        $this->assertStringContainsString('First line Second line', $failed);
        $this->assertFalse(str_contains($failed, "\033"));
        $this->assertFalse(str_contains($failed, 'hidden title'));
        $queue->push((new \Spark\Queue\Job('strlen', ['repeat']))->repeatEveryMinutes(5), 'emails');
        $repeated = $run();
        $this->assertTrue((bool) preg_match('/\.{2,} \d+\.\d{2}ms DONE$/m', $repeated));
        $this->assertTrue((bool) preg_match('/^ {21}↳ Next run: \d{4}-\d{2}-\d{2} /m', $repeated));
        $this->assertSame(1, substr_count($repeated, 'DONE'));
        $queue->clearAllJobs();
        $empty = $run();
        $this->assertFalse(str_contains($empty, 'timeout reached'));
        $queue->push(new \Spark\Queue\Job('strlen', ['multi']), 'emails');
        $multiple = $run(['emails', 'other']);
        $this->assertStringContainsString('Processing jobs from [emails, other].', $multiple);
        $this->assertTrue((bool) preg_match('/strlen  #\d+ · 1\/2 · emails /', $multiple));

        foreach (explode(PHP_EOL, trim($success . $retry . $failure . $repeated . $empty . $multiple)) as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertTrue((bool) preg_match('/^(?:\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}  | {21}↳ )/', $line));
            $this->assertFalse((bool) preg_match('/[\x00-\x1F\x7F]/', $line));
        }
    }
}
