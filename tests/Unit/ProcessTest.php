<?php

use Spark\Console\Exceptions\ProcessTimedOutException;
use Spark\Console\Process;
use Spark\Testing\TestCase;

final class ProcessTest extends TestCase
{
    public function test_large_input_and_output_do_not_deadlock_or_lose_bytes(): void
    {
        $input = str_repeat('input', 220000);
        $process = Process::command([
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, str_repeat("x", 1100000)); fwrite(STDERR, "diagnostic"); echo hash("sha256", stream_get_contents(STDIN));',
        ])->input($input)->timeout(5)->execute();

        $this->assertTrue($process->successful());
        $this->assertSame(str_repeat('x', 1100000) . hash('sha256', $input), $process->output());
        $this->assertSame('diagnostic', $process->errorOutput());
    }

    public function test_timeout_applies_while_child_does_not_read_input(): void
    {
        $started = microtime(true);
        $this->assertThrows(ProcessTimedOutException::class, fn() => Process::command([
            PHP_BINARY,
            '-r',
            'sleep(10);',
        ])->input(str_repeat('x', 1100000))->timeout(1)->execute());
        $this->assertTrue(microtime(true) - $started < 4);
    }

    public function test_exit_status_and_inherited_environment_survive_custom_variables(): void
    {
        $previous = getenv('SPARK_PROCESS_INHERITED');
        putenv('SPARK_PROCESS_INHERITED=retained');
        try {
            $process = Process::command([
                PHP_BINARY,
                '-r',
                'echo getenv("SPARK_PROCESS_INHERITED") . ":" . getenv("SPARK_PROCESS_CUSTOM"); exit(7);',
            ])->env(['SPARK_PROCESS_CUSTOM' => 'custom'])->execute();
            $this->assertSame('retained:custom', $process->output());
            $this->assertSame(7, $process->exitCode());
            $this->assertTrue($process->failed());
        } finally {
            putenv($previous === false ? 'SPARK_PROCESS_INHERITED' : 'SPARK_PROCESS_INHERITED=' . $previous);
        }
    }
}
