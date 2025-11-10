<?php

namespace Spark\Database\Casts;

use Closure;
use Spark\Database\Contracts\CastsAttributes;

/**
 * Class Attribute
 *
 * A class that allows defining custom getters and setters for model attributes
 * using closures.
 * 
 * @package Spark\Database\Casts
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Attribute implements CastsAttributes
{
    /**
     * Create a new Attribute cast instance.
     *
     * @param Closure $get The closure to get the attribute value.
     * @param Closure $set The closure to set the attribute value.
     */
    public function __construct(private Closure $get, private Closure $set)
    {
    }

    /**
     * Create a new Attribute cast instance.
     *
     * @param Closure $get The closure to get the attribute value.
     * @param Closure $set The closure to set the attribute value.
     * @return static
     */
    public static function make(Closure $get, Closure $set): static
    {
        return new static($get, $set);
    }

    /**
     * Get the value of the attribute after casting.
     *
     * @param mixed $value The original value from the database.
     * @return mixed The casted value.
     */
    public function get($value)
    {
        return ($this->get)($value);
    }

    /**
     * Set the value of the attribute before saving.
     *
     * @param mixed $value The value to be saved to the database.
     * @return mixed The value after applying any necessary transformations.
     */
    public function set($value)
    {
        return ($this->set)($value);
    }
}