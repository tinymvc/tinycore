<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Console\Commands;
use Spark\Console\Console;
use Spark\Console\Prompt;

final class ConsoleAnsiTest extends FrameworkTestCase
{
    public function test_global_color_flags_preserve_command_and_positional_arguments(): void
    {
        $previous = $GLOBALS['argv'];
        putenv('NO_COLOR=1');
        putenv('TERM=dumb');
        $received = null;
        $commands = new Commands();
        $commands->addCommand('probe', function (array $args) use (&$received): void {
            $received = $args;
            Prompt::success('Ready');
        });

        try {
            foreach ([
                [['--ansi', 'probe', 'recipient'], true, ['recipient']],
                [['probe', '--ansi', 'recipient'], true, ['recipient']],
                [['--no-ansi', 'probe', 'recipient'], false, ['recipient']],
                [['probe', '--ansi', '--no-ansi', 'recipient'], false, ['recipient']],
                [['probe', '--no-ansi', '--ansi', 'recipient'], false, ['recipient']],
                [['probe', '--', '--ansi'], false, ['--ansi']],
                [['--ansi', 'probe', '--', '--no-ansi'], true, ['--no-ansi']],
                [['probe', 'recipient'], false, ['recipient']],
            ] as [$arguments, $colors, $positional]) {
                $GLOBALS['argv'] = ['spark', ...$arguments];
                ob_start();
                try {
                    (new Console($commands))->run();
                    $output = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->assertSame(['_args' => $positional], $received);
                $this->assertSame($colors, str_contains($output, "\033[32m"));
                $this->assertStringContainsString('Ready', $output);
            }
        } finally {
            $GLOBALS['argv'] = $previous;
        }
    }

    public function test_queue_and_tinker_share_the_cli_color_preference(): void
    {
        $previous = $GLOBALS['argv'];
        putenv('NO_COLOR=1');
        putenv('TERM=dumb');
        $this->app->mergeConfig([
            'queue' => [
                'driver' => 'file',
                'connections' => ['file' => ['path' => $this->storagePath . '/ansi-queue']],
            ]
        ]);
        $queue = new \Spark\Queue\Queue();
        $tinker = (new ReflectionClass(\Spark\Tinker::class))->newInstanceWithoutConstructor();
        $color = new ReflectionMethod(\Spark\Tinker::class, 'color');

        try {
            foreach (['--ansi' => true, '--no-ansi' => false] as $flag => $colors) {
                $GLOBALS['argv'] = ['spark', 'queue:work', $flag];
                ob_start();
                try {
                    $queue->work(once: true, sleep: 0);
                    $output = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->assertSame($colors, str_contains($output, "\033[36mINFO"));
                $this->assertSame($colors, str_contains($color->invoke($tinker, 'value', 'green'), "\033"));
            }
        } finally {
            $GLOBALS['argv'] = $previous;
        }
    }

    public function test_test_runner_accepts_color_flags_in_captured_output(): void
    {
        putenv('NO_COLOR=1');
        putenv('TERM=dumb');
        $directory = $this->storagePath . '/ansi-tests';
        mkdir($directory);
        file_put_contents($directory . '/AnsiFixtureTest.php', <<<'PHP'
<?php
final class AnsiFixtureTest extends \Spark\Testing\TestCase
{
    public function test_passes(): void
    {
        $this->assertTrue(true);
    }
}
PHP);

        foreach ([[['--ansi'], true], [['--no-ansi'], false], [['--no-ansi', '--ansi'], false]] as [$arguments, $colors]) {
            ob_start();
            try {
                $status = (new \Spark\Testing\Runner())->run($directory, $arguments);
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertSame(0, $status);
            $this->assertSame($colors, str_contains($output, "\033"));
        }
    }
}
