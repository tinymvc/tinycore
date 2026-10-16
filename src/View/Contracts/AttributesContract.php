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
     * Merge class attribute strings.
     * 
     * @param string|array $classList The class or classes to include.
     * @return AttributesContract A new instance with the formatted class attribute.
     */
    public function class(string|array $classList): AttributesContract;

    /**
     * Merge style attribute strings.
     *
     * @param string|array $styleList The style or styles to include.
     * @return AttributesContract A new instance with the formatted style attribute.
     */
    public function style(string|array $styleList): AttributesContract;

    /**
     * Get the given attributes and remove them from the list.
     *
     * @param string|array $keys The attribute key or keys to retrieve.
     * @return array An associative array of the retrieved attributes.
     */
    public function props(string|array $keys): array;

    /**
     * Get the first attribute value in the collection.
     *
     * @return string|null
     */
    public function first(): ?string;

    /**
     * Determine if any of the given attribute keys exist.
     *
     * @param array|string $keys
     * @return bool
     */
    public function hasAny(array|string $keys): bool;

    /**
     * Filter attributes using a callback.
     *
     * @param callable $callback
     * @return static
     */
    public function filter(callable $callback): static;

    /**
     * Filter attributes that start with the given string(s).
     *
     * @param array|string $needles
     * @return static
     */
    public function whereStartsWith(array|string $needles): static;

    /**
     * Filter attributes that do not start with the given string(s).
     *
     * @param array|string $needles
     * @return static
     */
    public function whereDoesntStartWith(array|string $needles): static;

    /**
     * Alias for whereStartsWith.
     *
     * @param array|string $needles
     * @return static
     */
    public function thatStartWith(array|string $needles): static;

    /**
     * Get the underlying raw attributes array.
     *
     * @return array
     */
    public function getAttributes(): array;

    /**
     * Set the attributes array.
     *
     * @param array $attributes
     * @return static
     */
    public function setAttributes(array $attributes): static;

    /**
     * Determine if the attributes bag is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * Determine if the attributes bag is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool;
}