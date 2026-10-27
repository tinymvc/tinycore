<?php

namespace Spark\Http\Validation;

use Spark\Contracts\Support\Arrayable;
use Spark\Http\Validation\Rules\Exists;
use Spark\Http\Validation\Rules\In;
use Spark\Http\Validation\Rules\NotExists;
use Spark\Http\Validation\Rules\NotIn;
use Spark\Http\Validation\Rules\Password;
use Spark\Http\Validation\Rules\ProhibitedIf;
use Spark\Http\Validation\Rules\RequiredIf;
use Spark\Http\Validation\Rules\RequiredUnless;
use Spark\Http\Validation\Rules\Unique;

/**
 * Class Rule
 *
 * Provides factory methods for creating fluent validation rule instances.
 *
 * @package Spark\Http\Validation
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Rule
{
    /**
     * Create a new in validation rule.
     *
     * @param array|Arrayable $values
     * @return In
     */
    public static function in(array|Arrayable $values): In
    {
        return new In($values);
    }

    /**
     * Create a new not_in validation rule.
     *
     * @param array|Arrayable $values
     * @return NotIn
     */
    public static function notIn(array|Arrayable $values): NotIn
    {
        return new NotIn($values);
    }

    /**
     * Create a new unique validation rule for database checks.
     *
     * @param string $table
     * @param string|null $column
     * @return Unique
     */
    public static function unique(string $table, ?string $column = null): Unique
    {
        return new Unique($table, $column);
    }

    /**
     * Create a new exists validation rule for database checks.
     *
     * @param string $table
     * @param string|null $column
     * @return Exists
     */
    public static function exists(string $table, ?string $column = null): Exists
    {
        return new Exists($table, $column);
    }

    /**
     * Create a new not_exists validation rule for database checks.
     *
     * @param string $table
     * @param string|null $column
     * @return NotExists
     */
    public static function notExists(string $table, ?string $column = null): NotExists
    {
        return new NotExists($table, $column);
    }

    /**
     * Create a new required_if validation rule.
     *
     * @param bool|callable $condition
     * @return RequiredIf
     */
    public static function requiredIf(bool|callable $condition): RequiredIf
    {
        return new RequiredIf($condition);
    }

    /**
     * Create a new required_unless validation rule.
     *
     * @param bool|callable $condition
     * @return RequiredUnless
     */
    public static function requiredUnless(bool|callable $condition): RequiredUnless
    {
        return new RequiredUnless($condition);
    }

    /**
     * Create a new prohibited_if validation rule.
     *
     * @param bool|callable $condition
     * @return ProhibitedIf
     */
    public static function prohibitedIf(bool|callable $condition): ProhibitedIf
    {
        return new ProhibitedIf($condition);
    }

    /**
     * Create a new fluent password validation rule.
     *
     * @param int $min
     * @return Password
     */
    public static function password(int $min = 8): Password
    {
        return Password::min($min);
    }
}
