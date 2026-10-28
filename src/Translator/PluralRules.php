<?php

declare(strict_types=1);

namespace Spark\Translator;

use function is_float;
use function is_int;

/**
 * Compact CLDR cardinal rules for the listed languages.
 * Counts are numeric: visible trailing zeroes and compact-number exponents are not retained.
 *
 * Pipe forms ("one|few|many|other") map positionally onto categories();
 * array forms are keyed by category name. Unlisted languages use the
 * English rule (one: n = 1, other). Extend the two match() blocks, or
 * delegate to ext-intl's MessageFormatter, for more languages.
 */
final class PluralRules
{
    /** @return string[] ordered CLDR categories for pipe-positional forms */
    public static function categories(string $locale): array
    {
        return match (self::language($locale)) {
            'ja', 'zh', 'ko', 'vi', 'th', 'id', 'ms' => ['other'],
            'ru', 'uk', 'be', 'pl' => ['one', 'few', 'many', 'other'],
            'fr', 'pt' => ['one', 'many', 'other'],
            'ar' => ['zero', 'one', 'two', 'few', 'many', 'other'],
            default => ['one', 'other'],
        };
    }

    public static function category(string $locale, int|float $number): string
    {
        if (is_float($number) && !is_finite($number)) {
            throw new \InvalidArgumentException('Plural counts must be finite.');
        }
        $n = abs($number);
        $i = is_int($n) ? $n : floor($n);
        $whole = $n == $i;
        $i10 = is_int($i) ? $i % 10 : (int) fmod($i, 10);
        $i100 = is_int($i) ? $i % 100 : (int) fmod($i, 100);
        $million = $whole && $n != 0 && (is_int($i) ? $i % 1000000 === 0 : fmod($i, 1000000) == 0);
        $portuguesePortugal = strtolower(str_replace('-', '_', $locale)) === 'pt_pt';

        return match (self::language($locale)) {
            'ja', 'zh', 'ko', 'vi', 'th', 'id', 'ms' => 'other',

            // Bangla, Hindi, Persian, …: 0 and 1 are both singular (i = 0 or n = 1)
            'bn', 'hi', 'fa', 'gu', 'kn', 'zu', 'am' => $i == 0 || $n == 1 ? 'one' : 'other',

            // French, Portuguese: 0 and 1 (and 1.5) are singular
            'fr' => $i <= 1 ? 'one' : ($million ? 'many' : 'other'),
            'pt' => ($portuguesePortugal ? $whole && $n == 1 : $i <= 1) ? 'one' : ($million ? 'many' : 'other'),

            'ru', 'uk', 'be' => !$whole ? 'other' : match (true) {
                    $i10 === 1 && $i100 !== 11 => 'one',
                    $i10 >= 2 && $i10 <= 4 && ($i100 < 12 || $i100 > 14) => 'few',
                    default => 'many',
                },

            'pl' => !$whole ? 'other' : match (true) {
                    $i == 1 => 'one',
                    $i10 >= 2 && $i10 <= 4 && ($i100 < 12 || $i100 > 14) => 'few',
                    default => 'many',
                },

            'ar' => match (true) {
                    $n == 0 => 'zero',
                    $n == 1 => 'one',
                    $n == 2 => 'two',
                    $whole && $i100 >= 3 && $i100 <= 10 => 'few',
                    $whole && $i100 >= 11 => 'many',
                    default => 'other',
                },

            default => $n == 1 ? 'one' : 'other',
        };
    }

    private static function language(string $locale): string
    {
        return strtolower(substr($locale, 0, strcspn($locale, '-_')));
    }
}
