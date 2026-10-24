<?php

namespace Spark\Cache;

use DateInterval;
use DateTimeInterface;
use Spark\Carbon;
use function is_int;

/** @internal Normalize public expiration values before passing them to drivers. */
final class Expiration
{
    public static function timestamp(int|string|DateTimeInterface|Carbon|DateInterval $value): int
    {
        if (is_int($value)) {
            return Carbon::now()->getTimestamp() + $value;
        }

        if ($value instanceof DateInterval) {
            return Carbon::now()->toDateTimeImmutable()->add($value)->getTimestamp();
        }

        return Carbon::parse($value)->getTimestamp();
    }

    public static function forStorage(int|string|DateTimeInterface|Carbon|DateInterval|null $value): ?string
    {
        // Drivers reserve zero for forever; even pre-epoch deadlines must expire.
        return $value === null ? null : '@' . max(1, self::timestamp($value));
    }
}
