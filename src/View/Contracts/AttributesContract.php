<?php

namespace Spark\View\Contracts;

/**
 * Contract for managing HTML attributes in views.
 * 
 * This interface defines methods for retrieving, checking, filtering,
 * and manipulating HTML attributes.
 * 
 * @package Spark\View\Contracts
 */
interface AttributesContract
{
    /**
     * Get an attribute value by key.
     *
     * @param string $key The attribute key.
     * @param mixed $default The default value if the key does not exist.
     * @return mixed The attribute value or default.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Check if an attribute key exists.
     *
     * @param string|array $key The attribute key or keys to check.
     * @return bool True if the key(s) exist, false otherwise.
     */
    public function has(string|array $key): bool;

    /**
     * Get only the specified attributes.
     *
     * @param string|array|null $keys The attribute key or keys to include.
     * @return AttributesContract A new instance with only the specified attributes.
     */
    public function only(string|array|null $keys = null): AttributesContract;

    /**
     * Get all attributes except the specified ones.
     *
     * @param string|array|null $keys The attribute key or keys to exclude.
     * @return AttributesContract A new instance without the specified attributes.
     */
    public function except(string|array|null $keys = null): AttributesContract;

    /**
     * Merge the current attributes with default attributes.
     *
     * @param array $attributeDefaults The default attributes to merge.
     * @param bool $escape Whether to escape attribute values.
     * @return AttributesContract A new instance with merged attributes.
     */
    public function merge(array $attributeDefaults = [], bool $escape = true): AttributesContract;

    /**
     * Generate a class attribute string.
     *
     * @param string|array $classList The class or classes to include.
     * @return string The formatted class attribute string.
     */
    public function class(string|array $classList): string;

    /**
     * Generate a style attribute string.
     *
     * @param string|array $styleList The style or styles to include.
     * @return string The formatted style attribute string.
     */
    public function style(string|array $styleList): string;

    /**
     * Get the given attributes and remove them from the list.
     *
     * @param string|array $keys The attribute key or keys to retrieve.
     * @return array An associative array of the retrieved attributes.
     */
    public function props(string|array $keys): array;
}