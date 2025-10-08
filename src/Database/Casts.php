<?php

namespace Spark\Database;

use Spark\Contracts\Database\CastsAttributes;
use Spark\Support\Collection;
use Spark\Utils\Carbon;

/**
 * Trait Casts
 *
 * This trait provides functionality to cast model attributes
 * to specific data types when accessing or storing them.
 *
 * @package Spark\Database
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
trait Casts
{
    /**
     * Cast an attribute to a native PHP type.
     *
     * @param string $key The attribute key
     * @param mixed $value The raw value
     * @return mixed The cast value
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return $value;
        }

        $castType = $this->getCastType($key);

        if (!$castType) {
            return $value;
        }

        // Check if it's a custom cast class
        if ($this->isCustomCast($key)) {
            return $this->getCustomCastInstance($key)->get($value);
        }

        return match ($castType) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'decimal' => $this->asDecimal($value, $this->getCastParameters($key)),
            'string' => (string) $value,
            'bool', 'boolean' => $this->asBoolean($value),
            'object' => $this->fromJson($value, true),
            'array', 'json' => $this->fromJson($value),
            'collection' => $this->asCollection($value),
            'date' => $this->asDate($value),
            'datetime', 'timestamp' => $this->asDateTime($value),
            'encrypted' => $this->decrypt($value),
            default => $value,
        };
    }

    /**
     * Cast a value for storage in the database.
     *
     * @param string $key The attribute key
     * @param mixed $value The value to cast
     * @return mixed The cast value for storage
     */
    protected function castAttributeForStorage(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return $value;
        }

        $castType = $this->getCastType($key);

        if (!$castType) {
            return $value;
        }

        // Check if it's a custom cast class
        if ($this->isCustomCast($key)) {
            return $this->getCustomCastInstance($key)->set($value);
        }

        return match ($castType) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'decimal' => $this->asDecimal($value, $this->getCastParameters($key)),
            'string' => (string) $value,
            'bool', 'boolean' => $this->asBooleanForStorage($value),
            'object', 'array', 'json' => $this->asJson($value),
            'collection' => $this->asJson($value),
            'date', 'datetime', 'timestamp' => $this->asDateForStorage($value),
            'encrypted' => $this->encrypt($value),
            default => $value,
        };
    }

    /**
     * Get the cast type for the given attribute.
     *
     * @param string $key The attribute key
     * @return string|null The cast type or null if not cast
     */
    protected function getCastType(string $key): ?string
    {
        if (!$this->hasCast($key)) {
            return null;
        }

        $cast = $this->casts[$key];

        // If it's a custom cast class, return the class name
        if (class_exists($cast)) {
            return $cast;
        }

        // Handle cast with parameters like 'decimal:2'
        if (str_contains($cast, ':')) {
            return explode(':', $cast, 2)[0];
        }

        return $cast;
    }

    /**
     * Get the cast parameters for the given attribute.
     *
     * @param string $key The attribute key
     * @return array The cast parameters
     */
    protected function getCastParameters(string $key): array
    {
        if (!$this->hasCast($key)) {
            return [];
        }

        $cast = $this->casts[$key];

        if (str_contains($cast, ':')) {
            $parameters = explode(':', $cast, 2)[1];
            return explode(',', $parameters);
        }

        return [];
    }

    /**
     * Check if the given attribute should be cast.
     *
     * @param string $key The attribute key
     * @return bool Whether the attribute should be cast
     */
    public function hasCast(string $key): bool
    {
        return isset($this->casts, $this->casts[$key]);
    }

    /**
     * Check if any of the given attributes should be cast.
     *
     * @return bool Whether any of the attributes should be cast
     */
    public function hasAnyCast(array|string ...$keys): bool
    {
        return isset($this->casts) && !empty($this->casts);
    }

    /**
     * Cast a value to a boolean.
     *
     * @param mixed $value The value to cast
     * @return bool The boolean value
     */
    protected function asBoolean(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_string($value) => in_array(strtolower($value), ['true', '1', 'yes', 'on']),
            is_numeric($value) => (bool) $value,
            default => (bool) $value,
        };
    }

    /**
     * Cast a boolean value for storage.
     *
     * @param mixed $value The value to cast
     * @return int The integer representation (0 or 1)
     */
    protected function asBooleanForStorage(mixed $value): int
    {
        return $this->asBoolean($value) ? 1 : 0;
    }

    /**
     * Cast a value to a decimal.
     *
     * @param mixed $value The value to cast
     * @param array $parameters The decimal parameters [precision]
     * @return string The decimal value
     */
    protected function asDecimal(mixed $value, array $parameters = []): string
    {
        $precision = $parameters[0] ?? 2;
        return number_format((float) $value, (int) $precision, '.', '');
    }

    /**
     * Cast a value to a date.
     *
     * @param mixed $value The value to cast
     * @return \Spark\Utils\Carbon|null The date object
     */
    protected function asDate(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return carbon($value);
    }

    /**
     * Cast a value to a datetime.
     *
     * @param mixed $value The value to cast
     * @return \Spark\Utils\Carbon|null The datetime object
     */
    protected function asDateTime(mixed $value): ?Carbon
    {
        return $this->asDate($value);
    }

    /**
     * Cast a date value for storage.
     *
     * @param mixed $value The value to cast
     * @return string|null The date string for storage
     */
    protected function asDateForStorage(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        if (is_string($value)) {
            $date = carbon($value);
            return $date->toDateTimeString();
        }

        return (string) $value;
    }

    /**
     * Cast a value to a collection.
     *
     * @param mixed $value The value to cast
     * @return \Spark\Support\Collection The collection
     */
    protected function asCollection(mixed $value): Collection
    {
        return collect($this->fromJson($value));
    }

    /**
     * Decode a JSON string.
     *
     * @param mixed $value The value to decode
     * @param bool $asObject Whether to return as object
     * @return mixed The decoded value
     */
    protected function fromJson(mixed $value, bool $asObject = false): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, !$asObject);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * Encode a value as JSON.
     *
     * @param mixed $value The value to encode
     * @return string|null The JSON string
     */
    protected function asJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Encrypt a value.
     *
     * @param mixed $value The value to encrypt
     * @return string|null The encrypted value
     */
    protected function encrypt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Encrypt the value using Application's encrypt helper
        return encrypt($value);
    }

    /**
     * Decrypt a value.
     *
     * @param mixed $value The value to decrypt
     * @return mixed The decrypted value or null if decryption fails
     */
    protected function decrypt(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            // Decrypt the value using Application's decrypt helper
            return decrypt($value);
        } catch (\Exception) {
            // Decryption failed, return null
        }

        return null;
    }

    /**
     * Check if the given attribute is cast to a custom cast class.
     *
     * @param string $key The attribute key
     * @return bool Whether the attribute is cast to a custom class
     */
    protected function isCustomCast(string $key): bool
    {
        if (!$this->hasCast($key)) {
            return false;
        }

        $cast = $this->casts[$key];

        // Check if it's a class that exists and implements CastsAttributes
        return class_exists($cast) && is_subclass_of($cast, CastsAttributes::class);
    }

    /**
     * Get an instance of the custom cast class for the given attribute.
     *
     * @param string $key The attribute key
     * @return \Spark\Contracts\Database\CastsAttributes The cast instance
     * @throws \InvalidArgumentException If the cast is not a valid custom cast class
     */
    protected function getCustomCastInstance(string $key): CastsAttributes
    {
        if ($this->isCustomCast($key)) {
            throw new \InvalidArgumentException("No custom cast defined for attribute: {$key}");
        }

        $castClass = $this->casts[$key];

        // Create and return an instance of the cast class
        return new $castClass();
    }
}
