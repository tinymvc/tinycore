<?php

namespace Spark\Testing;

use Throwable;

/** Extend this for unit tests; use ApplicationTestCase for application tests. */
abstract class TestCase extends Assert
{
    private ?string $expectedException = null;
    private ?string $expectedMessage = null;
    private int|string|null $expectedCode = null;

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    protected function expectException(string $class): void
    {
        if (!is_a($class, Throwable::class, true)) {
            throw new \InvalidArgumentException($class . ' is not a Throwable class.');
        }
        $this->expectedException = $class;
    }

    /** Match a substring of the expected exception's message. */
    protected function expectExceptionMessage(string $message): void
    {
        $this->expectedMessage = $message;
    }

    protected function expectExceptionCode(int|string $code): void
    {
        $this->expectedCode = $code;
    }

    protected function markTestSkipped(string $reason = 'Test skipped.'): never
    {
        throw new SkippedTest($reason);
    }

    /** @internal Run one test and preserve both test and cleanup failures. */
    final public function runTest(string $method): array
    {
        $before = self::countAssertions();
        $reporting = error_reporting(E_ALL);
        $previousHandler = set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $level)) {
                return false;
            }
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
        $bufferLevel = ob_get_level();
        ob_start();
        $output = '';
        $errors = [];
        $skipped = null;
        $this->expectedException = $this->expectedMessage = null;
        $this->expectedCode = null;
        try {
            $this->setUp();
            $thrown = null;
            try {
                $this->$method();
            } catch (Throwable $e) {
                $thrown = $e;
            }

            // Assertion failures must never satisfy an expected application exception.
            if ($thrown instanceof AssertionFailed || $thrown instanceof SkippedTest) {
                throw $thrown;
            }
            if ($this->expectedException !== null || $this->expectedMessage !== null || $this->expectedCode !== null) {
                if ($thrown === null) {
                    self::fail('Expected exception ' . ($this->expectedException ?? '') . ' was not thrown.');
                }
                if ($this->expectedException !== null) {
                    self::assertInstanceOf($this->expectedException, $thrown);
                }
            } elseif ($thrown !== null) {
                throw $thrown;
            }
            if ($this->expectedMessage !== null) {
                if ($thrown === null) {
                    self::fail('Expected an exception message, but no exception was thrown.');
                }
                self::assertStringContainsString($this->expectedMessage, $thrown->getMessage());
            }
            if ($this->expectedCode !== null) {
                self::assertSame($this->expectedCode, $thrown->getCode());
            }
        } catch (SkippedTest $e) {
            $skipped = $e->getMessage();
        } catch (Throwable $e) {
            $errors[] = $e;
        } finally {
            try {
                $this->tearDown();
            } catch (Throwable $e) {
                $errors[] = $e;
            }
        }
        try {
            if (ob_get_level() <= $bufferLevel) {
                $errors[] = new AssertionFailed('Test closed an output buffer owned by the runner.');
            }
            while (ob_get_level() > $bufferLevel) {
                $level = ob_get_level();
                $output = (string) ob_get_clean() . $output;
                if (ob_get_level() === $level) {
                    $errors[] = new AssertionFailed('Test left a non-removable output buffer.');
                    break;
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e;
        } finally {
            $this->restoreErrorHandler($previousHandler);
            error_reporting($reporting);
        }
        $assertions = self::countAssertions() - $before;
        if ($errors === [] && $skipped === null && $assertions === 0) {
            $errors[] = new AssertionFailed('Test did not make any assertions.');
        }
        return ['assertions' => $assertions, 'errors' => $errors, 'skipped' => $skipped, 'output' => $output];
    }
    /** Unwind handlers left by a test, restoring the caller's active handler. */
    private function restoreErrorHandler(?callable $previous): void
    {
        while (true) {
            $current = set_error_handler(static fn() => false);
            restore_error_handler();
            if ($current === $previous) {
                return;
            }
            if ($current === null) {
                if ($previous !== null) {
                    set_error_handler($previous);
                }
                return;
            }
            restore_error_handler();
        }
    }
}
