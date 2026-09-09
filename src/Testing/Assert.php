<?php

namespace Spark\Testing;

/** Small, strict assertions that work regardless of PHP's zend.assertions setting. */
class Assert
{
    private static int $assertions = 0;

    public static function countAssertions(): int
    {
        return self::$assertions;
    }

    public static function fail(string $message = 'Test failed.'): never
    {
        throw new AssertionFailed($message);
    }

    public static function assertTrue(mixed $actual, string $message = ''): void
    {
        self::assertSame(true, $actual, $message);
    }

    public static function assertFalse(mixed $actual, string $message = ''): void
    {
        self::assertSame(false, $actual, $message);
    }

    public static function assertNull(mixed $actual, string $message = ''): void
    {
        self::assertSame(null, $actual, $message);
    }

    public static function assertNotNull(mixed $actual, string $message = ''): void
    {
        self::assertNotSame(null, $actual, $message);
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::check($expected === $actual, $message ?: 'Expected ' . self::describe($expected) . ', got ' . self::describe($actual) . '.');
    }

    public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::check($expected !== $actual, $message ?: 'Did not expect ' . self::describe($actual) . '.');
    }

    public static function assertCount(int $expected, array|\Countable $actual, string $message = ''): void
    {
        self::assertSame($expected, count($actual), $message);
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::check(str_contains($haystack, $needle), $message ?: 'Expected the string to contain ' . self::describe($needle) . '.');
    }

    public static function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        self::check(array_key_exists($key, $array), $message ?: 'Missing array key ' . self::describe($key) . '.');
    }

    public static function assertArrayNotHasKey(string|int $key, array $array, string $message = ''): void
    {
        self::check(!array_key_exists($key, $array), $message ?: 'Unexpected array key ' . self::describe($key) . '.');
    }

    public static function assertInstanceOf(string $class, mixed $actual, string $message = ''): void
    {
        self::check($actual instanceof $class, $message ?: 'Expected an instance of ' . $class . '.');
    }

    /** PHP value equality (==); use assertSame when types must match. */
    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::check($expected == $actual, $message ?: 'Expected values to be equal.');
    }

    public static function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::check($expected != $actual, $message ?: 'Expected values to differ.');
    }

    public static function assertEqualsWithDelta(float $expected, float $actual, float $delta, string $message = ''): void
    {
        if ($delta < 0 || !is_finite($delta)) {
            throw new \InvalidArgumentException('Delta must be finite and non-negative.');
        }
        self::check($expected === $actual || abs($expected - $actual) <= $delta, $message ?: 'Values differ by more than ' . $delta . '.');
    }

    public static function assertEmpty(mixed $actual, string $message = ''): void
    {
        self::check($actual instanceof \Countable ? count($actual) === 0 : empty($actual), $message ?: 'Expected an empty value.');
    }

    public static function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        self::check($actual instanceof \Countable ? count($actual) > 0 : !empty($actual), $message ?: 'Expected a non-empty value.');
    }

    public static function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        foreach ($haystack as $value) {
            if ($value === $needle) {
                self::check(true, '');
                return;
            }
        }
        self::check(false, $message ?: 'Expected the iterable to contain ' . self::describe($needle) . '.');
    }

    public static function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        foreach ($haystack as $value) {
            if ($value === $needle) {
                self::check(false, $message ?: 'Unexpected iterable value ' . self::describe($needle) . '.');
            }
        }
        self::check(true, '');
    }

    public static function assertGreaterThan(int|float $expected, int|float $actual, string $message = ''): void
    {
        self::check($actual > $expected, $message ?: "Expected $actual to be greater than $expected.");
    }

    public static function assertGreaterThanOrEqual(int|float $expected, int|float $actual, string $message = ''): void
    {
        self::check($actual >= $expected, $message ?: "Expected $actual to be at least $expected.");
    }

    public static function assertLessThan(int|float $expected, int|float $actual, string $message = ''): void
    {
        self::check($actual < $expected, $message ?: "Expected $actual to be less than $expected.");
    }

    public static function assertLessThanOrEqual(int|float $expected, int|float $actual, string $message = ''): void
    {
        self::check($actual <= $expected, $message ?: "Expected $actual to be at most $expected.");
    }

    public static function assertIsArray(mixed $actual, string $message = ''): void
    {
        self::check(is_array($actual), $message ?: 'Expected an array.');
    }

    public static function assertIsString(mixed $actual, string $message = ''): void
    {
        self::check(is_string($actual), $message ?: 'Expected a string.');
    }

    public static function assertIsInt(mixed $actual, string $message = ''): void
    {
        self::check(is_int($actual), $message ?: 'Expected an integer.');
    }

    public static function assertIsFloat(mixed $actual, string $message = ''): void
    {
        self::check(is_float($actual), $message ?: 'Expected a float.');
    }

    public static function assertIsBool(mixed $actual, string $message = ''): void
    {
        self::check(is_bool($actual), $message ?: 'Expected a boolean.');
    }

    public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::check(!str_contains($haystack, $needle), $message ?: 'Unexpected string ' . self::describe($needle) . '.');
    }

    public static function assertStringStartsWith(string $prefix, string $actual, string $message = ''): void
    {
        self::check(str_starts_with($actual, $prefix), $message ?: 'Unexpected string prefix.');
    }

    public static function assertStringEndsWith(string $suffix, string $actual, string $message = ''): void
    {
        self::check(str_ends_with($actual, $suffix), $message ?: 'Unexpected string suffix.');
    }

    public static function assertMatchesRegularExpression(string $pattern, string $actual, string $message = ''): void
    {
        self::check(preg_match($pattern, $actual) === 1, $message ?: 'String does not match ' . $pattern . '.');
    }

    public static function assertFileExists(string $path, string $message = ''): void
    {
        self::check(is_file($path), $message ?: 'File does not exist: ' . $path);
    }

    public static function assertFileDoesNotExist(string $path, string $message = ''): void
    {
        self::check(!is_file($path), $message ?: 'File unexpectedly exists: ' . $path);
    }

    public static function assertDirectoryExists(string $path, string $message = ''): void
    {
        self::check(is_dir($path), $message ?: 'Directory does not exist: ' . $path);
    }

    /** Assert one operation throws, then continue testing its returned exception. */
    public static function assertThrows(string $class, callable $callback, ?string $message = null): \Throwable
    {
        if (!is_a($class, \Throwable::class, true)) {
            throw new \InvalidArgumentException($class . ' is not a Throwable class.');
        }
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof AssertionFailed || $e instanceof SkippedTest) {
                throw $e;
            }
            self::assertInstanceOf($class, $e);
            if ($message !== null) {
                self::assertStringContainsString($message, $e->getMessage());
            }
            return $e;
        }
        self::fail('Expected exception ' . $class . ' was not thrown.');
    }

    private static function check(bool $condition, string $message): void
    {
        self::$assertions++;
        if (!$condition) {
            self::fail($message);
        }
    }

    private static function describe(mixed $value): string
    {
        if (is_object($value)) {
            return get_debug_type($value) . '#' . spl_object_id($value);
        }
        if (is_array($value)) {
            return substr((string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 1000);
        }
        return substr(var_export($value, true), 0, 1000);
    }
}
