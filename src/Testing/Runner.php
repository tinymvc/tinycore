<?php

namespace Spark\Testing;

use ReflectionClass;
use ReflectionMethod;
use Throwable;
use function count;
use function get_class;
use function in_array;
use function is_string;
use function sprintf;
use function strlen;

/** Discover public test* methods in *Test.php files and run them sequentially. */
final class Runner
{
    /** @var bool Whether to use ANSI colors in the output. */
    private bool $colors = false;

    /**
     * Run the test runner.
     * 
     * @param string $directory The directory to search for test files.
     * @param array $arguments The command line arguments.
     * @return int The exit code: 0 if all tests passed, 1 if any test failed, 2 if an error occurred.
     */
    public function run(string $directory, array $arguments = []): int
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('The test runner is available only in CLI.');
        }

        $start = hrtime(true);
        $this->colors = function_exists('stream_isatty') && stream_isatty(STDOUT)
            && getenv('TERM') !== 'dumb'
            && (getenv('NO_COLOR') === false || getenv('NO_COLOR') === '');

        set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $level)) {
                return false;
            }
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        try {
            [$suite, $filter, $list, $help] = $this->options($arguments);
            if ($help) {
                echo "Usage: php test [--testsuite Unit|Feature] [--filter text] [--list-tests]\n";
                return 0;
            }

            $tests = $this->discover($suite === null ? $directory : "$directory/$suite", $filter);
            if ($tests === []) {
                fwrite(STDERR, "No tests found.\n");
                return 1;
            }

            if ($list) {
                foreach ($tests as [$class, $method]) {
                    echo "$class::$method\n";
                }
                return 0;
            }

            $failed = $passed = $assertions = $skipped = 0;
            $currentClass = null;
            $results = [];

            foreach ($tests as [$class, $method]) {
                if ($currentClass !== null && $currentClass !== $class) {
                    $this->reportClass($currentClass, $results);
                    $results = [];
                }

                $currentClass = $class;
                $testStart = hrtime(true);
                $before = Assert::countAssertions();

                try {
                    $result = (new $class())->runTest($method);
                } catch (Throwable $e) {
                    $result = ['errors' => [$e], 'skipped' => null, 'output' => ''];
                }

                // Include assertions in constructors, even when construction fails.
                $result['assertions'] = Assert::countAssertions() - $before;
                $result['duration'] = (hrtime(true) - $testStart) / 1e9;
                $assertions += $result['assertions'];

                // Cleanup errors take precedence over a skip or successful test body.
                if ($result['errors'] !== []) {
                    $result['status'] = 'FAIL';
                    $failed++;
                } elseif ($result['skipped'] !== null) {
                    $result['status'] = 'SKIP';
                    $skipped++;
                } else {
                    $result['status'] = 'PASS';
                    $passed++;
                }

                $results[$method] = $result;
            }

            $this->reportClass($currentClass, $results);
            $this->reportSummary($passed, $failed, $skipped, $assertions, (hrtime(true) - $start) / 1e9);

            return $failed > 0 ? 1 : 0;
        } catch (Throwable $e) {
            fwrite(STDERR, "Test runner error: " . $e->getMessage() . "\n");

            $this->report($e);
            return 2;
        } finally {
            restore_error_handler();
        }
    }

    /** Render only after the class has finished so its badge reflects every result. */
    private function reportClass(string $class, array $results): void
    {
        $statuses = array_column($results, 'status');
        $status = match (true) {
            in_array('FAIL', $statuses, true) => 'FAIL',
            !in_array('PASS', $statuses, true) => 'SKIP',
            in_array('SKIP', $statuses, true) => 'WARN',
            default => 'PASS',
        };

        $color = match ($status) {
            'PASS' => '30;42',
            'FAIL' => '97;41',
            default => '30;43',
        };

        echo "\n  " . $this->style(" $status ", $color) . " $class\n";

        $columns = getenv('COLUMNS');
        $width = is_string($columns) && preg_match('/^[0-9]+$/D', $columns) ? max(40, min(160, (int) $columns)) : 80;

        foreach ($results as $method => $result) {
            $color = match ($result['status']) {
                'PASS' => '32',
                'FAIL' => '31',
                default => '33',
            };

            $symbol = match ($result['status']) {
                'PASS' => '✓',
                'FAIL' => '⨯',
                default => '-',
            };

            $name = $this->testName($method);
            $duration = $this->duration($result['duration']);
            $length = function_exists('mb_strwidth') ? mb_strwidth($name, 'UTF-8') : strlen($name);
            $padding = max(2, $width - $length - strlen($duration) - 6);

            echo '  ' . $this->style($symbol, $color) . ' ' . $this->style($name, '90')
                . str_repeat(' ', $padding) . $this->style($duration, '90') . "\n";

            if ($result['skipped'] !== null) {
                $this->reportText('Skipped: ' . $result['skipped'], '33');
            }
            if (($result['output'] ?? '') !== '') {
                $this->reportText("OUTPUT $class::$method", '90');
                $this->reportText($result['output']);
            }
            if ($result['errors'] !== []) {
                $this->reportText("FAIL $class::$method", '31');
                foreach ($result['errors'] as $error) {
                    $this->report($error);
                }
            }
        }
    }

    private function reportSummary(int $passed, int $failed, int $skipped, int $assertions, float $duration): void
    {
        $counts = [];
        foreach ([[$failed, 'failed', '31'], [$skipped, 'skipped', '33'], [$passed, 'passed', '32']] as [$count, $label, $color]) {
            if ($count > 0) {
                $counts[] = $this->style("$count $label", $color);
            }
        }

        $assertionLabel = $assertions === 1 ? 'assertion' : 'assertions';
        echo "\n  Tests:    " . implode(', ', $counts) . $this->style(" ($assertions $assertionLabel)", '90') . "\n";
        echo '  Duration: ' . $this->duration($duration) . "\n\n";
    }

    private function testName(string $method): string
    {
        $name = substr($method, 4);
        $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $name);
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);
        $name = trim(preg_replace('/[_\s]+/', ' ', $name));

        return $name === '' ? $method : strtolower($name);
    }

    private function duration(float $seconds): string
    {
        return $seconds < 0.001 ? '<0.001s' : sprintf('%.3fs', $seconds);
    }

    private function style(string $text, string $color): string
    {
        return $this->colors ? "\033[{$color}m$text\033[0m" : $text;
    }

    private function reportText(string $text, string $color = ''): void
    {
        foreach (explode("\n", rtrim(str_replace(["\r\n", "\r"], "\n", $text), "\n")) as $line) {
            echo '      ' . ($color === '' ? $line : $this->style($line, $color)) . "\n";
        }
    }

    private function options(array $arguments): array
    {
        $suite = null;
        $filter = '';
        $list = $help = false;

        for ($i = 0; $i < count($arguments); $i++) {
            [$option, $value] = array_pad(explode('=', $arguments[$i], 2), 2, null);

            if ($value !== null && in_array($option, ['--help', '-h', '--list-tests'], true)) {
                throw new \InvalidArgumentException("$option does not accept a value.");
            }

            if ($option === '--help' || $option === '-h') {
                $help = true;
            } elseif ($option === '--list-tests') {
                $list = true;
            } elseif ($option === '--filter' || $option === '--testsuite') {
                $value ??= $arguments[++$i] ?? null;
                if ($value === null || $value === '' || str_starts_with($value, '--')) {
                    throw new \InvalidArgumentException('Missing value for ' . $option . '.');
                }

                if ($option === '--filter') {
                    $filter = $value;
                } else {
                    $suite = ucfirst(strtolower($value));
                    if (!in_array($suite, ['Unit', 'Feature'], true)) {
                        throw new \InvalidArgumentException("Unknown test suite: $value");
                    }
                }
            } else {
                throw new \InvalidArgumentException("Unknown option: $option");
            }
        }

        return [$suite, $filter, $list, $help];
    }

    private function discover(string $directory, string $filter): array
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Test directory does not exist: $directory");
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getRealPath();
            }
        }

        sort($files);
        foreach ($files as $file) {
            require_once $file;
        }

        $tests = [];
        foreach (get_declared_classes() as $class) {
            $reflection = new ReflectionClass($class);
            if (
                $reflection->isAbstract() || $reflection->isAnonymous() || !$reflection->isSubclassOf(TestCase::class)
                || !in_array($reflection->getFileName(), $files, true)
            ) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!str_starts_with($method->name, 'test')) {
                    continue;
                }

                if ($method->isStatic() || $method->getNumberOfParameters() > 0) {
                    throw new \LogicException("$class::{$method->name} must be a non-static method with no parameters.");
                }

                if ($filter === '' || str_contains("$class::$method->name", $filter)) {
                    $tests[] = [$class, $method->name];
                }
            }
        }

        sort($tests);
        return $tests;
    }

    private function report(Throwable $error): void
    {
        $file = $error->getFile();
        $line = $error->getLine();
        if ($error instanceof AssertionFailed) {
            foreach ($error->getTrace() as $frame) {
                if (isset($frame['file']) && !str_starts_with($frame['file'], __DIR__ . DIRECTORY_SEPARATOR)) {
                    $file = $frame['file'];
                    $line = $frame['line'];
                    break;
                }
            }
        }

        $this->reportText(get_class($error) . ': ' . $error->getMessage(), '31');
        $this->reportText("$file:$line", '90');
    }
}
