<?php

namespace Spark\Testing;

use ReflectionClass;
use ReflectionMethod;
use Throwable;
use function count;
use function in_array;
use function printf;

/** Discover public test* methods in *Test.php files and run them sequentially. */
final class Runner
{
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

        $start = microtime(true);
        set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $level)) {
                return false;
            }
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        try {
            [$suite, $filter, $list, $help] = $this->options($arguments);
            if ($help) {
                echo "Usage: php tests/run.php [--testsuite Unit|Feature] [--filter text] [--list-tests]\n";
                return 0;
            }

            $tests = $this->discover($suite === null ? $directory : "$directory/$suite", $filter);
            if ($tests === []) {
                fwrite(STDERR, "No tests found.\n");
                return 1;
            }

            $failed = $assertions = $skipped = 0;
            foreach ($tests as [$class, $method]) {
                $name = "$class::$method";
                if ($list) {
                    echo "$name\n";
                    continue;
                }
                try {
                    $result = (new $class())->runTest($method);
                } catch (Throwable $e) {
                    $result = ['assertions' => 0, 'errors' => [$e], 'skipped' => null];
                }

                $assertions += $result['assertions'];
                if (($result['output'] ?? '') !== '') {
                    echo "OUTPUT $name\n" . $result['output'] . "\n";
                }

                if ($result['errors'] === []) {
                    if ($result['skipped'] !== null) {
                        $skipped++;
                        echo "SKIP $name: " . $result['skipped'] . "\n";
                    } else {
                        echo "PASS $name\n";
                    }

                    continue;
                }

                $failed++;

                echo "FAIL $name\n";
                foreach ($result['errors'] as $error) {
                    $this->report($error);
                }
            }

            if (!$list) {
                printf("\n%d tests, %d assertions, %d failures, %d skipped (%.3fs)\n", count($tests), $assertions, $failed, $skipped, microtime(true) - $start);
            }

            return $failed > 0 ? 1 : 0;
        } catch (Throwable $e) {
            fwrite(STDERR, "Test runner error: " . $e->getMessage() . "\n");

            $this->report($e);
            return 2;
        } finally {
            restore_error_handler();
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

        printf("  %s: %s\n  %s:%d\n", get_class($error), $error->getMessage(), $file, $line);
    }
}
