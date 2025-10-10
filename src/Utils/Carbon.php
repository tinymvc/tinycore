<?php

namespace Spark\Utils;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use Spark\Contracts\Support\Arrayable;

/**
 * Custom DateTime Utility Class
 *  
 * This class provides a comprehensive set of methods for handling date and time operations,
 * making it easier to work with dates and times in a consistent manner.
 *
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Carbon implements Arrayable, \Stringable
{
    /** @var DateTime */
    private DateTime $dateTime;

    /** @var string|null */
    private static ?string $defaultTimezone = null;

    /**
     * Constructor to create a new DateTime instance
     *
     * @param string $time The time string to parse, defaults to 'now'
     * @param DateTimeZone|null $timezone The timezone to use, defaults to the default timezone
     */
    public function __construct(string $time = 'now', ?DateTimeZone $timezone = null)
    {
        $this->dateTime = new DateTime($time, $timezone ?? $this->getDefaultTimezone());
    }

    /**
     * Create a new DateTime instance for the current time
     * 
     * @param DateTimeZone|null $timezone The timezone to use, defaults to the default timezone
     * @return self
     */
    public static function now(?DateTimeZone $timezone = null): self
    {
        return new self('now', $timezone);
    }

    /**
     * Create a new DateTime instance for today at midnight
     * 
     * @param DateTimeZone|null $timezone The timezone to use, defaults to the default timezone
     * @return self
     */
    public static function today(?DateTimeZone $timezone = null): self
    {
        return new self('today', $timezone);
    }

    /**
     * Create a new DateTime instance for tomorrow at midnight
     * 
     * @param DateTimeZone|null $timezone The timezone to use, defaults to the default timezone
     * @return self
     */
    public static function tomorrow(?DateTimeZone $timezone = null): self
    {
        return new self('tomorrow', $timezone);
    }

    /**
     * Create a new DateTime instance for yesterday at midnight
     * 
     * @param DateTimeZone|null $timezone The timezone to use, defaults to the default timezone
     * @return self
     */
    public static function yesterday(?DateTimeZone $timezone = null): self
    {
        return new self('yesterday', $timezone);
    }

    /**
     * Create DateTime from format
     * 
     * @param string $format The format to use for parsing the datetime string
     * @param string $datetime The datetime string to parse
     * @param DateTimeZone|null $timezone The timezone to use, defaults to the default timezone
     * @return self
     */
    public static function createFromFormat(string $format, string $datetime, ?DateTimeZone $timezone = null): self
    {
        $dt = DateTime::createFromFormat($format, $datetime, $timezone ?? static::getDefaultTimezone());
        if ($dt === false) {
            throw new InvalidArgumentException("Could not parse datetime: {$datetime} with format: {$format}");
        }

        $instance = new self();
        $instance->dateTime = $dt;
        return $instance;
    }

    /**
     * Create DateTime from timestamp
     * 
     * @param int $timestamp The Unix timestamp to create the DateTime from
     * @param DateTimeZone|null $timezone The timezone to use, defaults to the default timezone
     * @return self
     */
    public static function createFromTimestamp(int $timestamp, ?DateTimeZone $timezone = null): self
    {
        $instance = new self();
        $instance->dateTime = new DateTime("@$timestamp");
        if ($timezone) {
            $instance->dateTime->setTimezone($timezone);
        }
        return $instance;
    }

    /**
     * Parse a datetime string
     * 
     * @param string $datetime The datetime string to parse
     * @param DateTimeZone|null $timezone The timezone to use, defaults to the default timezone
     * @return self
     */
    public static function parse(string $datetime, ?DateTimeZone $timezone = null): self
    {
        return new self($datetime, $timezone);
    }

    /**
     * Set default timezone for all instances
     * 
     * @param string|DateTimeZone $timezone The timezone to set as default
     * @throws InvalidArgumentException if the timezone is invalid
     * @return void
     */
    public static function setDefaultTimezone(string|DateTimeZone $timezone): void
    {
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }
        self::$defaultTimezone = $timezone->getName();
    }

    /**
     * Get default timezone
     * 
     * This method returns the default timezone set for the class.
     * If no default timezone has been set, it returns the system's default timezone.
     * 
     * @return DateTimeZone
     */
    private static function getDefaultTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::$defaultTimezone ?? date_default_timezone_get());
    }

    /**
     * Format the DateTime instance
     * 
     * @param string $format The format to use for formatting the date
     * @return string The formatted date string
     */
    public function format(string $format): string
    {
        return $this->dateTime->format($format);
    }

    /**
     * These methods provide various formats for the DateTime instance.
     * 
     * @return string The formatted date string
     */
    public function toDateString(): string
    {
        return $this->format('Y-m-d');
    }

    /**
     * Get the time as a string in 'H:i:s' format
     * 
     * @return string The formatted time string
     */
    public function toTimeString(): string
    {
        return $this->format('H:i:s');
    }

    /**
     * Get the date and time as a string in 'Y-m-d H:i:s' format
     * 
     * @return string The formatted date and time string
     */
    public function toDateTimeString(): string
    {
        return $this->format('Y-m-d H:i:s');
    }

    /**
     * Get the date and time as a string in ISO 8601 format
     * 
     * @return string The formatted date and time string in ISO 8601 format
     */
    public function toISOString(): string
    {
        return $this->format(\DateTimeInterface::ATOM);
    }

    /**
     * Get the date as a string in 'M j, Y' format
     * 
     * @return string The formatted date string
     */
    public function toFormattedDateString(): string
    {
        return $this->format('M j, Y');
    }

    /**
     * Convert the DateTime instance to an array representation
     * 
     * @return array An associative array with date and time components
     */
    public function toArray(): array
    {
        return [
            'year' => (int) $this->format('Y'),
            'month' => (int) $this->format('n'),
            'day' => (int) $this->format('j'),
            'hour' => (int) $this->format('G'),
            'minute' => (int) $this->format('i'),
            'second' => (int) $this->format('s'),
            'dayOfWeek' => (int) $this->format('w'),
            'dayOfYear' => (int) $this->format('z'),
            'weekOfYear' => (int) $this->format('W'),
            'timestamp' => $this->getTimestamp(),
        ];
    }

    /**
     * Getters for various date and time components
     * 
     * These methods provide access to individual components of the DateTime instance.
     * 
     * @return int The timestamp of the DateTime instance
     */
    public function getTimestamp(): int
    {
        return $this->dateTime->getTimestamp();
    }

    /**
     * Get the year, month, day, hour, minute, second, and day of week
     * 
     * These methods return the respective components of the DateTime instance.
     * 
     * @return int The respective component value
     */
    public function getYear(): int
    {
        return (int) $this->format('Y');
    }

    /**
     * Get the month as an integer (1-12)
     * 
     * @return int The month of the year
     */
    public function getMonth(): int
    {
        return (int) $this->format('n');
    }

    /**
     * Get the day of the month (1-31)
     * 
     * @return int The day of the month
     */
    public function getDay(): int
    {
        return (int) $this->format('j');
    }

    /**
     * Get the hour (0-23)
     * 
     * @return int The hour of the day
     */
    public function getHour(): int
    {
        return (int) $this->format('G');
    }

    /**
     * Get the minute (0-59)
     * 
     * @return int The minute of the hour
     */
    public function getMinute(): int
    {
        return (int) $this->format('i');
    }

    /**
     * Get the second (0-59)
     * 
     * @return int The second of the minute
     */
    public function getSecond(): int
    {
        return (int) $this->format('s');
    }

    /**
     * Get the day of the week (0-6, where 0 = Sunday)
     * 
     * @return int The day of the week
     */
    public function getDayOfWeek(): int
    {
        return (int) $this->format('w');
    }

    /**
     * Get the day of the year (1-366)
     * 
     * @return int The day of the year
     */
    public function getDayName(): string
    {
        return $this->format('l');
    }

    /**
     * Get the week of the year (1-53)
     * 
     * @return int The week of the year
     */
    public function getMonthName(): string
    {
        return $this->format('F');
    }

    /**
     * Add years to the DateTime instance
     * 
     * @param int $years The number of years to add
     * @return self A new DateTime instance with the years added
     */
    public function addYears(int $years): self
    {
        $new = clone $this;
        $new->dateTime->modify("+{$years} years");
        return $new;
    }

    /**
     * Add months to the DateTime instance
     * 
     * @param int $months The number of months to add
     * @return self A new DateTime instance with the months added
     */
    public function addMonths(int $months): self
    {
        $new = clone $this;
        $new->dateTime->modify("+{$months} months");
        return $new;
    }

    /**
     * Add days to the DateTime instance
     * 
     * @param int $days The number of days to add
     * @return self A new DateTime instance with the days added
     */
    public function addDays(int $days): self
    {
        $new = clone $this;
        $new->dateTime->modify("+{$days} days");
        return $new;
    }

    /**
     * Add hours to the DateTime instance
     * 
     * @param int $hours The number of hours to add
     * @return self A new DateTime instance with the hours added
     */
    public function addHours(int $hours): self
    {
        $new = clone $this;
        $new->dateTime->modify("+{$hours} hours");
        return $new;
    }

    /**
     * Add minutes to the DateTime instance
     * 
     * @param int $minutes The number of minutes to add
     * @return self A new DateTime instance with the minutes added
     */
    public function addMinutes(int $minutes): self
    {
        $new = clone $this;
        $new->dateTime->modify("+{$minutes} minutes");
        return $new;
    }

    /**
     * Add seconds to the DateTime instance
     * 
     * @param int $seconds The number of seconds to add
     * @return self A new DateTime instance with the seconds added
     */
    public function addSeconds(int $seconds): self
    {
        $new = clone $this;
        $new->dateTime->modify("+{$seconds} seconds");
        return $new;
    }

    /**
     * Subtract years from the DateTime instance
     * 
     * @param int $years The number of years to subtract
     * @return self A new DateTime instance with the years subtracted
     */
    public function subYears(int $years): self
    {
        $new = clone $this;
        $new->dateTime->modify("-{$years} years");
        return $new;
    }

    /**
     * Subtract months from the DateTime instance
     * 
     * @param int $months The number of months to subtract
     * @return self A new DateTime instance with the months subtracted
     */
    public function subMonths(int $months): self
    {
        $new = clone $this;
        $new->dateTime->modify("-{$months} months");
        return $new;
    }

    /**
     * Subtract days from the DateTime instance
     * 
     * @param int $days The number of days to subtract
     * @return self A new DateTime instance with the days subtracted
     */
    public function subDays(int $days): self
    {
        $new = clone $this;
        $new->dateTime->modify("-{$days} days");
        return $new;
    }

    /**
     * Subtract hours from the DateTime instance
     * 
     * @param int $hours The number of hours to subtract
     * @return self A new DateTime instance with the hours subtracted
     */
    public function subHours(int $hours): self
    {
        $new = clone $this;
        $new->dateTime->modify("-{$hours} hours");
        return $new;
    }

    /**
     * Subtract minutes from the DateTime instance
     * 
     * @param int $minutes The number of minutes to subtract
     * @return self A new DateTime instance with the minutes subtracted
     */
    public function subMinutes(int $minutes): self
    {
        $new = clone $this;
        $new->dateTime->modify("-{$minutes} minutes");
        return $new;
    }

    /**
     * Subtract seconds from the DateTime instance
     * 
     * @param int $seconds The number of seconds to subtract
     * @return self A new DateTime instance with the seconds subtracted
     */
    public function subSeconds(int $seconds): self
    {
        $new = clone $this;
        $new->dateTime->modify("-{$seconds} seconds");
        return $new;
    }

    /**
     * Get the start of the day for the DateTime instance
     * 
     * @return self A new DateTime instance set to the start of the day
     */
    public function startOfDay(): self
    {
        $new = clone $this;
        $new->dateTime->setTime(0, 0, 0);
        return $new;
    }

    /**
     * Get the end of the day for the DateTime instance
     * 
     * @return self A new DateTime instance set to the end of the day
     */
    public function endOfDay(): self
    {
        $new = clone $this;
        $new->dateTime->setTime(23, 59, 59);
        return $new;
    }

    /**
     * Get the start of the week for the DateTime instance
     * 
     * @return self A new DateTime instance set to the start of the week
     */
    public function startOfWeek(): self
    {
        $new = clone $this;
        $new->dateTime->modify('Monday this week')->setTime(0, 0, 0);
        return $new;
    }

    /**
     * Get the end of the week for the DateTime instance
     * 
     * @return self A new DateTime instance set to the end of the week
     */
    public function endOfWeek(): self
    {
        $new = clone $this;
        $new->dateTime->modify('Sunday this week')->setTime(23, 59, 59);
        return $new;
    }

    /**
     * Get the start of the month for the DateTime instance
     * 
     * @return self A new DateTime instance set to the start of the month
     */
    public function startOfMonth(): self
    {
        $new = clone $this;
        $new->dateTime->modify('first day of this month')->setTime(0, 0, 0);
        return $new;
    }

    /**
     * Get the end of the month for the DateTime instance
     * 
     * @return self A new DateTime instance set to the end of the month
     */
    public function endOfMonth(): self
    {
        $new = clone $this;
        $new->dateTime->modify('last day of this month')->setTime(23, 59, 59);
        return $new;
    }

    /**
     * Get the start of the year for the DateTime instance
     * 
     * @return self A new DateTime instance set to the start of the year
     */
    public function startOfYear(): self
    {
        $new = clone $this;
        $new->dateTime->setDate($this->getYear(), 1, 1)->setTime(0, 0, 0);
        return $new;
    }

    /**
     * Get the end of the year for the DateTime instance
     * 
     * @return self A new DateTime instance set to the end of the year
     */
    public function endOfYear(): self
    {
        $new = clone $this;
        $new->dateTime->setDate($this->getYear(), 12, 31)->setTime(23, 59, 59);
        return $new;
    }

    /**
     * Check if the DateTime instance is today
     * 
     * @return bool True if the DateTime instance is today, false otherwise
     */
    public function isToday(): bool
    {
        return $this->toDateString() === self::now()->toDateString();
    }

    /**
     * Check if the DateTime instance is tomorrow
     * 
     * @return bool True if the DateTime instance is tomorrow, false otherwise
     */
    public function isTomorrow(): bool
    {
        return $this->toDateString() === self::tomorrow()->toDateString();
    }

    /**
     * Check if the DateTime instance is yesterday
     * 
     * @return bool True if the DateTime instance is yesterday, false otherwise
     */
    public function isYesterday(): bool
    {
        return $this->toDateString() === self::yesterday()->toDateString();
    }

    /**
     * Check if the DateTime instance is in the future
     * 
     * @return bool True if the DateTime instance is in the future, false otherwise
     */
    public function isFuture(): bool
    {
        return $this->getTimestamp() > time();
    }

    /**
     * Check if the DateTime instance is in the past
     * 
     * @return bool True if the DateTime instance is in the past, false otherwise
     */
    public function isPast(): bool
    {
        return $this->getTimestamp() < time();
    }

    /**
     * Check if the DateTime instance is on a weekend
     * 
     * @return bool True if the DateTime instance is on a weekend, false otherwise
     */
    public function isWeekend(): bool
    {
        $dayOfWeek = $this->getDayOfWeek();
        return $dayOfWeek === 0 || $dayOfWeek === 6; // Sunday = 0, Saturday = 6
    }

    /**
     * Check if the DateTime instance is a weekday
     * 
     * @return bool True if the DateTime instance is a weekday, false if it's a weekend
     */
    public function isWeekday(): bool
    {
        return !$this->isWeekend();
    }

    /**
     * Check if the DateTime instance is on the same day as another DateTime instance
     * 
     * @param self $other The other DateTime instance to compare with
     * @return bool True if both instances are on the same day, false otherwise
     */
    public function isSameDay(self $other): bool
    {
        return $this->toDateString() === $other->toDateString();
    }

    /**
     * Check if the DateTime instance is in the same month as another DateTime instance
     * 
     * @param self $other The other DateTime instance to compare with
     * @return bool True if both instances are in the same month, false otherwise
     */
    public function isSameMonth(self $other): bool
    {
        return $this->format('Y-m') === $other->format('Y-m');
    }

    /**
     * Check if the DateTime instance is in the same year as another DateTime instance
     * 
     * @param self $other The other DateTime instance to compare with
     * @return bool True if both instances are in the same year, false otherwise
     */
    public function isSameYear(self $other): bool
    {
        return $this->getYear() === $other->getYear();
    }

    /**
     * Check if the DateTime instance is after or before another DateTime instance
     * 
     * @param self $other The other DateTime instance to compare with
     * @return bool True if this instance is after the other, false otherwise
     */
    public function isAfter(self $other): bool
    {
        return $this->getTimestamp() > $other->getTimestamp();
    }

    /**
     * Check if the DateTime instance is before another DateTime instance
     * 
     * @param self $other The other DateTime instance to compare with
     * @return bool True if this instance is before the other, false otherwise
     */
    public function isBefore(self $other): bool
    {
        return $this->getTimestamp() < $other->getTimestamp();
    }

    /**
     * Check if two DateTime instances are equal
     * 
     * @param self $other The other DateTime instance to compare with
     * @return bool True if both instances represent the same point in time, false otherwise
     */
    public function equals(self $other): bool
    {
        return $this->getTimestamp() === $other->getTimestamp();
    }

    /**
     * Calculate the difference in seconds, minutes, hours, or days between two DateTime instances
     * 
     * @param self $other The other DateTime instance to compare with
     * @return int The difference in seconds, minutes, hours, or days
     */
    public function diffInSeconds(self $other): int
    {
        return abs($this->getTimestamp() - $other->getTimestamp());
    }

    /**
     * Calculate the difference in minutes between two DateTime instances
     * 
     * @param self $other The other DateTime instance to compare with
     * @return int The difference in minutes
     */
    public function diffInMinutes(self $other): int
    {
        return (int) ($this->diffInSeconds($other) / 60);
    }

    /**
     * Calculate the difference in hours between two DateTime instances
     * 
     * @param self $other The other DateTime instance to compare with
     * @return int The difference in hours
     */
    public function diffInHours(self $other): int
    {
        return (int) ($this->diffInSeconds($other) / 3600);
    }

    /**
     * Calculate the difference in days between two DateTime instances
     * 
     * @param self $other The other DateTime instance to compare with
     * @return int The difference in days
     */
    public function diffInDays(self $other): int
    {
        return (int) ($this->diffInSeconds($other) / 86400);
    }

    /**
     * Calculate the difference in months between two DateTime instances
     * 
     * @param self $other The other DateTime instance to compare with
     * @return int The difference in months
     */
    public function diffForHumans(?self $other = null): string
    {
        $other ??= self::now();
        $diff = $this->getTimestamp() - $other->getTimestamp();
        $absDiff = abs($diff);

        $future = $diff > 0;

        if ($absDiff < 60) {
            return $future ? 'in a few seconds' : 'a few seconds ago';
        } elseif ($absDiff < 3600) {
            $minutes = round($absDiff / 60);
            return $future ? "in {$minutes} minutes" : "{$minutes} minutes ago";
        } elseif ($absDiff < 86400) {
            $hours = round($absDiff / 3600);
            return $future ? "in {$hours} hours" : "{$hours} hours ago";
        } elseif ($absDiff < 2592000) {
            $days = round($absDiff / 86400);
            return $future ? "in {$days} days" : "{$days} days ago";
        } elseif ($absDiff < 31536000) {
            $months = round($absDiff / 2592000);
            return $future ? "in {$months} months" : "{$months} months ago";
        } else {
            $years = round($absDiff / 31536000);
            return $future ? "in {$years} years" : "{$years} years ago";
        }
    }

    /**
     * Get a short human-readable difference between two DateTime instances
     * 
     * This method returns a shortened version of the time difference, suitable for compact display.
     * Examples: "1m ago", "2h ago", "3d ago", "1y ago", "5m", "2h", etc.
     * 
     * @param self|null $other The other DateTime instance to compare with, defaults to now
     * @param bool $withSuffix Whether to include "ago" suffix for past times, defaults to true
     * @return string The short human-readable time difference
     */
    public function diffForHumansShort(?self $other = null, bool $withSuffix = true): string
    {
        $other ??= self::now();
        $diff = $this->getTimestamp() - $other->getTimestamp();
        $absDiff = abs($diff);

        $future = $diff > 0;

        // Determine the appropriate unit and value
        if ($absDiff < 60) {
            $value = $absDiff;
            $unit = 's';
        } elseif ($absDiff < 3600) {
            $value = round($absDiff / 60);
            $unit = 'm';
        } elseif ($absDiff < 86400) {
            $value = round($absDiff / 3600);
            $unit = 'h';
        } elseif ($absDiff < 2592000) { // 30 days
            $value = round($absDiff / 86400);
            $unit = 'd';
        } elseif ($absDiff < 31536000) { // 365 days
            $value = round($absDiff / 2592000);
            $unit = 'mo';
        } else {
            $value = round($absDiff / 31536000);
            $unit = 'y';
        }

        // Handle very small differences
        if ($value == 0) {
            return $withSuffix ? 'now' : '0s';
        }

        // Build the result string
        $result = "{$value}{$unit}";

        if ($withSuffix) {
            if ($future) {
                $result = "in {$result}";
            } else {
                $result = "{$result} ago";
            }
        }

        return $result;
    }

    /**
     * Set the timezone for the DateTime instance
     * 
     * @param string|DateTimeZone $timezone The timezone to set
     * @return self A new DateTime instance with the specified timezone
     */
    public function setTimezone(string|DateTimeZone $timezone): self
    {
        $new = clone $this;
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }
        $new->dateTime->setTimezone($timezone);
        return $new;
    }

    /**
     * Get the timezone of the DateTime instance
     * 
     * @return DateTimeZone The timezone of the DateTime instance
     */
    public function getTimezone(): DateTimeZone
    {
        return $this->dateTime->getTimezone();
    }

    /** Comparison methods */
    public function lt(self $other): bool
    {
        return $this->getTimestamp() < $other->getTimestamp();
    }

    public function gt(self $other): bool
    {
        return $this->getTimestamp() > $other->getTimestamp();
    }

    public function lte(self $other): bool
    {
        return $this->getTimestamp() <= $other->getTimestamp();
    }

    public function gte(self $other): bool
    {
        return $this->getTimestamp() >= $other->getTimestamp();
    }

    /**
     * Clone the DateTime instance
     * 
     * This method creates a new instance that is a clone of the current DateTime instance.
     * 
     * @return self A new DateTime instance that is a clone of the current instance
     */
    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Convert the DateTime instance to a string
     * 
     * This method returns the DateTime instance as a string in 'Y-m-d H:i:s' format.
     * 
     * @return string The formatted date and time string
     */
    public function __toString(): string
    {
        return $this->toDateTimeString();
    }

    /**
     * Clone method to ensure the DateTime instance is cloned correctly
     * 
     * This method is called when the DateTime instance is cloned, ensuring that the internal DateTime object is also cloned.
     */
    public function __clone()
    {
        $this->dateTime = clone $this->dateTime;
    }

    /**
     * Static methods to get the maximum and minimum dates from a list of DateTime instances
     * 
     * These methods return the maximum or minimum date from a list of DateTime instances.
     * 
     * @param self ...$dates The DateTime instances to compare
     * @return self The maximum or minimum DateTime instance
     */
    public static function maxDate(self ...$dates): self
    {
        if (empty($dates)) {
            throw new InvalidArgumentException('At least one date must be provided');
        }

        return array_reduce($dates, function ($max, $current) {
            return $max === null || $current->isAfter($max) ? $current : $max;
        });
    }

    /**
     * Static methods to get the minimum date from a list of DateTime instances
     * 
     * These methods return the minimum date from a list of DateTime instances.
     * 
     * @param self ...$dates The DateTime instances to compare
     * @return self The minimum DateTime instance
     */
    public static function minDate(self ...$dates): self
    {
        if (empty($dates)) {
            throw new InvalidArgumentException('At least one date must be provided');
        }

        return array_reduce($dates, function ($min, $current) {
            return $min === null || $current->isBefore($min) ? $current : $min;
        });
    }

    /**
     * Calculate the age in years from a given birth date
     * 
     * This method calculates the age in years from the current date or a specified birth date.
     * 
     * @param self|null $birthDate The birth date to calculate the age from, defaults to the current instance
     * @return int The age in years
     */
    public function age(?self $birthDate = null): int
    {
        $birthDate ??= $this;
        $now = self::now();

        return (int) $now->dateTime->diff($birthDate->dateTime)->format('%y');
    }

    /**
     * Clone the DateTime instance
     * 
     * This method creates a new instance that is a clone of the current DateTime instance.
     * 
     * @return self A new DateTime instance that is a clone of the current instance
     */
    public function copy(): self
    {
        return clone $this;
    }
}
