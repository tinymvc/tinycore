<?php

use Spark\Console\Process;
use Spark\Testing\TestCase;

final class ConcurrencySchedulerTest extends TestCase
{
    public function test_pending_tasks_continue_after_startup_and_execution_failures(): void
    {
        $process = Process::run([PHP_BINARY, '-n', dirname(__DIR__) . '/Fixtures/parallel-scheduler.php'], timeout: 5);
        $this->assertTrue($process->successful(), $process->errorOutput());
        $this->assertSame([
            'startup' => ['error' => true, 'message' => 'startup failure'],
            'failure' => ['error' => true, 'message' => 'task failure'],
            'success' => 'completed',
            'last' => 42,
        ], json_decode($process->output(), true, flags: JSON_THROW_ON_ERROR));
    }
}
