<?php

namespace Spark\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Spark\Carbon;

/**
 * Standard PSR-20 Clock implementation for Spark.
 */
class Clock implements ClockInterface
{
    /**
     * @param DateTimeZone|string|null $timezone
     */
    public function __construct(private DateTimeZone|string|null $timezone = null)
    {
    }

    /**
     * Returns the current time as a DateTimeImmutable Object.
     *
     * @return DateTimeImmutable
     */
    public function now(): DateTimeImmutable
    {
        return Carbon::now($this->timezone)->toDateTimeImmutable();
    }
}
