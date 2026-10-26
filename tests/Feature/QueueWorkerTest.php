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

    public function test_restart_finishes_and_acknowledges_the_current_job_before_stopping(): void
    {
        $queue = $this->queue();
        foreach ([false, true] as $fail) {
            $path = $this->storagePath . '/restart-job';
            $queue->push(new Job(RestartWorkerJob::class, [$path, $fail]));
            $queue->push(new Job('strlen', ['pending']));
            ob_start();
            try {
                $queue->work(timeout: 2, sleep: 0, tries: 1);
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertSame('finished', file_get_contents($path));
            $this->assertStringContainsString('Worker restart requested.', $output);
            $this->assertCount(1, $queue->getJobs(status: 'pending'));
            $this->assertCount($fail ? 1 : 0, $queue->getFailedJobs());
            $queue->clearAllJobs();
        }
    }

    public function test_restart_command_is_registered_and_new_workers_accept_existing_signals(): void
    {
        $queue = $this->queue();
        $provider = new Spark\Foundation\Providers\ConsoleServiceProvider($this->app);
        $provider->register();
        $provider->boot();
        $commands = $this->app->get(Spark\Console\Commands::class)->getAllCommands();
        $this->assertTrue(isset($commands['queue:restart']));
        [$handler, $method] = $commands['queue:restart']['callback'];
        ob_start();
        try {
            (new $handler())->$method($queue);
            $first = cache('queue-workers')->retrieve('restart');
            (new $handler())->$method($queue);
            $this->assertTrue(is_string($first));
            $this->assertTrue($first !== cache('queue-workers')->retrieve('restart'));
            $path = $this->storagePath . '/fresh-worker';
            $queue->push(new Job('file_put_contents', [$path, 'done']));
            $queue->work(once: true, sleep: 0);
            $this->assertSame('done', file_get_contents($path));
            $this->assertCount(0, $queue->getJobs());
        } finally {
            ob_end_clean();
        }
    }

    public function test_idle_worker_observes_restart_from_another_process(): void
    {
        $queue = $this->queue();
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $path = var_export($this->storagePath, true);
        $script = $this->storagePath . '/idle-worker.php';
        file_put_contents($script, '<?php require ' . $autoload . '; $path = ' . $path . ';' . <<<'PHP'
            Spark\Foundation\Application::create(path: $path, config: [
                'app' => ['storage_dir' => $path],
                'cache' => ['driver' => 'file', 'connections' => ['file' => ['path' => $path . '/cache']]],
                'queue' => ['default' => 'file', 'connections' => ['file' => ['path' => $path . '/queue']]],
            ], providers: []);
            (new Spark\Queue\Queue())->work(timeout: 3, sleep: 0);
            PHP);
        $process = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $this->assertTrue(is_resource($process));
        fclose($pipes[0]);
        try {
            $output = '';
            while (($line = fgets($pipes[1])) !== false) {
                $output .= $line;
                if (str_contains($line, 'Processing jobs')) {
                    $queue->restart();
                    break;
                }
            }
            $output .= stream_get_contents($pipes[1]);
        } finally {
            fclose($pipes[1]);
            $status = proc_close($process);
        }
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Worker restart requested.', $output);
        $this->assertFalse(str_contains($output, 'Worker timeout reached.'));
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

final class RestartWorkerJob
{
    public function __construct(private string $path, private bool $fail)
    {
    }

    public function handle(): void
    {
        (new Queue())->restart();
        file_put_contents($this->path, 'finished');
        if ($this->fail) {
            throw new RuntimeException('Failure after restart request.');
        }
    }
}

final class ThrowingQueueConstructor
{
    public function __construct()
    {
        throw new RuntimeException('Cannot construct job.');
    }
}
