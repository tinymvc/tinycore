<?php

declare(strict_types=1);

namespace Spark\Translator;

use InvalidArgumentException;
use Spark\Translator\Contracts\TranslatorContract;
use Stringable;
use function array_key_exists;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;

/** @internal Translation argument formatting and placeholder replacement. */
final class Formatter
{
    /** Resolve a string with named, positional, printf, or plural arguments. */
    public static function format(
        TranslatorContract $translator,
        string $text,
        mixed $arg = null,
        array $args = [],
        array $args2 = [],
        ?string $locale = null
    ): string {
        $number = is_numeric($arg) ? $arg + 0 : null;
        $arguments = $args2 !== [] && $number !== null && $number != 1
            ? $args2
            : ($args ?: (is_array($arg) ? $arg : ($arg === null ? [] : [$arg])));

        $named = $arguments !== [] && !array_is_list($arguments);
        $replace = $named ? $arguments : [];
        $line = $number !== null
            ? $translator->choice($text, $number, $replace, $locale)
            : $translator->get($text, $replace, $locale);

        if (is_array($line)) {
            $line = $translator->choice($text, 1, $replace, $locale);
        }
        if ($named || $arguments === []) {
            return $line;
        }
        if (preg_match_all('/:\\w+/', $line, $matches)) {
            $pairs = [];
            foreach (array_values(array_unique($matches[0])) as $index => $placeholder) {
                if (array_key_exists($index, $arguments)) {
                    $pairs[$placeholder] = self::stringify($arguments[$index]);
                }
            }
            return strtr($line, $pairs);
        }
        if (!str_contains($line, '%')) {
            return $line;
        }
        try {
            return vsprintf($line, $arguments);
        } catch (\ValueError | \ArgumentCountError) {
            return $line;
        }
    }

    /** :name  :NAME  :Name — longest placeholder wins, replaced values are never re-processed. */
    public static function replace(string|array $line, array $replace): string|array
    {
        if ($replace === []) {
            return $line;
        }

        if (is_array($line)) {
            foreach ($line as $k => $value) {
                if (is_string($value) || is_array($value)) {
                    $line[$k] = self::replace($value, $replace);
                }
            }
            return $line;
        }

        if (!str_contains($line, ':')) {
            return $line;
        }

        $pairs = [];
        foreach ($replace as $key => $value) {
            $key = (string) $key;
            $text = self::stringify($value);
            $pairs[":$key"] = $text;

            if ($key !== strtolower($key)) {
                continue;
            }

            // Case variants are built only when the line actually uses them.
            $upper = ':' . strtoupper($key);
            if ($upper !== ":$key" && str_contains($line, $upper)) {
                $pairs[$upper] = function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
            }

            $first = ':' . ucfirst($key);
            if ($first !== ":$key" && str_contains($line, $first)) {
                $pairs[$first] = function_exists('mb_strtoupper') && function_exists('mb_substr')
                    ? mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8')
                    : ucfirst($text);
            }
        }

        return strtr($line, $pairs);
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException('Replacement values must be scalar, null or Stringable.'),
        };
    }
}
