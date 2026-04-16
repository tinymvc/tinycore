<?php

namespace Spark\Database\Traits;

use Spark\Utils\Carbon;
use function in_array;

/**
 * Trait HasTimestamp
 * 
 * This trait provides functionality for handling created_at and updated_at timestamps in a database model. 
 * It defines constants for the timestamp fields, methods to retrieve the timestamps as Carbon instances, 
 * and a method to prepare the timestamps for storage when saving the model.
 * 
 * @package Spark\Database\Traits
 * @author  Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
trait HasTimestamp
{
    /** The name of the "created at" column. */
    protected const string CREATED_AT = 'created_at';

    /** The name of the "updated at" column. */
    protected const string UPDATED_AT = 'updated_at';

    /**
     * Get the value of the created_at attribute as a Carbon instance.
     *
     * @return Carbon The created_at timestamp as a Carbon instance.
     */
    public function getCreatedAtAttribute(): Carbon
    {
        return Carbon::parse($this->attributes[static::CREATED_AT] ?? 'now');
    }

    /**
     * Get the value of the updated_at attribute as a Carbon instance.
     *
     * @return Carbon The updated_at timestamp as a Carbon instance.
     */
    public function getUpdatedAtAttribute(): Carbon
    {
        return Carbon::parse($this->attributes[static::UPDATED_AT] ?? 'now');
    }

    /**
     * Get the names of the timestamp fields.
     *
     * @return array An array containing the names of the created_at and updated_at fields.
     */
    protected function getTimestamps(): array
    {
        return [static::CREATED_AT, static::UPDATED_AT];
    }

    /**
     * Prepare the timestamp attributes for storage when saving the model.
     *
     * @return array An array containing the casted timestamps for storage.
     */
    protected function getCastedTimestampsForStorage(): array
    {
        $timestamps = $this->getTimestamps();

        $castedTimestamps = [];

        if (in_array(static::CREATED_AT, $timestamps)) {
            $castedTimestamps[static::CREATED_AT] = $this->attributes[static::CREATED_AT] ??= Carbon::now()->toDateTimeString();
        }

        if (in_array(static::UPDATED_AT, $timestamps)) {
            if ($this->isDirty()) {
                unset($this->attributes[static::UPDATED_AT]);
            }

            $castedTimestamps[static::UPDATED_AT] = $this->attributes[static::UPDATED_AT] ??= Carbon::now()->toDateTimeString();
        }

        return $castedTimestamps;
    }
}