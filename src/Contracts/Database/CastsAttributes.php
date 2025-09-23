<?php

namespace Spark\Contracts\Database;

/**
 * Interface CastsAttributes
 *
 * This interface defines the methods required for custom
 * cast classes that handle the conversion of model attributes
 * to and from specific data types.
 *
 * @package Spark\Contracts\Database
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
interface CastsAttributes
{
    /**
     * Get the value of the attribute after casting.
     *
     * @param mixed $value The original value from the database.
     * @return mixed The casted value.
     */
    public function get($value);

    /**
     * Set the value of the attribute before saving.
     *
     * @param mixed $value The value to be saved to the database.
     * @return mixed The value after applying any necessary transformations.
     */
    public function set($value);
}