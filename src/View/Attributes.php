<?php

namespace Spark\View;

use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Htmlable;
use Spark\Support\Arr;
use Spark\Support\Str;
use Spark\View\Contracts\AttributesContract;
use function array_key_exists;
use function func_get_args;
use function is_array;
use function is_bool;
use function is_int;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Class Attributes
 *
 * A class to manage HTML attributes for Blade components.
 * 
 * This class provides methods to manipulate and render HTML attributes,
 * including merging, filtering, and converting to string or HTML.
 * 
 * @package Spark\View
 * @implements Arrayable<string, mixed>
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 * @implements Htmlable
 * @implements \Stringable
 * 
 * @since 2.1.2
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Attributes implements AttributesContract, Arrayable, Htmlable, \Stringable, \ArrayAccess, \IteratorAggregate
{
    /**
     * The raw array of attributes.
     */
    protected array $attributes = [];

    /**
     * Create a new attributes instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Get the first attribute's key.
     */
    public function first(): ?string
    {
        return array_key_first($this->attributes);
    }

    /**
     * Get a given attribute from the attributes array.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Determine if a given attribute exists.
     */
    public function has(string|array $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $value) {
            if (!array_key_exists($value, $this->attributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if any of the keys exist.
     */
    public function hasAny(string|array $keys): bool
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        if (empty($keys)) {
            return false;
        }

        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only include the given attribute from the attributes array.
     */
    public function only(string|array|null $keys = null): static
    {
        if ($keys === null) {
            $values = $this->attributes;
        } else {
            $keys = is_array($keys) ? $keys : func_get_args();
            $values = Arr::only($this->attributes, $keys);
        }

        return new static($values);
    }

    /**
     * Exclude the given attribute from the attributes array.
     */
    public function except(string|array|null $keys = null): static
    {
        if ($keys === null) {
            $values = $this->attributes;
        } else {
            $keys = is_array($keys) ? $keys : func_get_args();
            $values = Arr::except($this->attributes, $keys);
        }

        return new static($values);
    }

    /**
     * Filter the attributes.
     */
    public function filter(callable $callback): static
    {
        return new static(array_filter($this->attributes, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Return a bag of attributes that have keys starting with the given value.
     */
    public function whereStartsWith(string|array $needles): static
    {
        return $this->filter(fn($value, $key) => Str::startsWith($key, $needles));
    }

    /**
     * Return a bag of attributes that have keys that do not start with the given value.
     */
    public function whereDoesntStartWith(string|array $needles): static
    {
        return $this->filter(fn($value, $key) => !Str::startsWith($key, $needles));
    }

    /**
     * Return a bag of attributes with keys in the given list.
     */
    public function thatStartWith(string|array $needles): static
    {
        return $this->whereStartsWith($needles);
    }

    /**
     * Get the given attributes and remove them from the list.
     */
    public function props(string|array $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $props = [];

        // Handle numeric keys as keys with null values (defaults)
        foreach ($keys as $key => $value) {
            if (is_int($key)) {
                $key = $value;
                $value = null;
            }

            $props[$key] = $this->attributes[$key] ?? $value;

            unset($this->attributes[$key]); // Remove the key from attributes
        }

        return $props;
    }

    /**
     * Merge the class attributes.
     * 
     * @param string|array $classList
     */
    public function class(string|array $classList): static
    {
        return $this->merge(['class' => $classList]);
    }

    /**
     * Merge the style attributes.
     * 
     * @param string|array $styleList
     */
    public function style(string|array $styleList): static
    {
        return $this->merge(['style' => $styleList]);
    }

    /**
     * Merge additional attributes / values into the attributes.
     */
    public function merge(array $attributeDefaults = [], bool $escape = true): static
    {
        $attributeDefaults = $this->parseAttributeDefaults($attributeDefaults);

        [$appendableAttributes, $nonAppendableAttributes] = $this->partitionAttributes();

        $attributes = [];

        foreach ($appendableAttributes as $key => $value) {
            $defaultsValue = $attributeDefaults[$key] ?? '';

            if ($key === 'class') {
                $attributes[$key] = $this->mergeClasses($defaultsValue, $value, $escape);
            } elseif ($key === 'style') {
                $attributes[$key] = $this->mergeStyles($defaultsValue, $value, $escape);
            } else {
                $attributes[$key] = $this->mergeDefault($defaultsValue, $value, $escape);
            }
        }

        $attributes = [...$attributes, ...$nonAppendableAttributes];

        return new static([...$attributeDefaults, ...$attributes]);
    }

    /**
     * Parse and escape attribute defaults.
     */
    protected function parseAttributeDefaults(array $attributeDefaults): array
    {
        $result = [];

        foreach ($attributeDefaults as $key => $value) {
            // Skip false and null values
            if ($value === false || $value === null) {
                continue;
            }

            // Keep true as-is for boolean attributes
            if ($value === true) {
                $result[$key] = true;
                continue;
            }

            // Normalize class and style
            if ($key === 'class') {
                $result[$key] = $this->normalizeClassValue($value);
            } elseif ($key === 'style') {
                $result[$key] = $this->normalizeStyleValue($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Partition attributes into appendable and non-appendable.
     */
    protected function partitionAttributes(): array
    {
        $appendable = [];
        $nonAppendable = [];

        foreach ($this->attributes as $key => $value) {
            if ($key === 'class' || $key === 'style') {
                $appendable[$key] = $value;
            } else {
                $nonAppendable[$key] = $value;
            }
        }

        return [$appendable, $nonAppendable];
    }

    /**
     * Merge class attributes.
     */
    protected function mergeClasses(string $defaults, mixed $value, bool $escape): string
    {
        $defaults = $this->normalizeClassValue($defaults);
        $value = $this->normalizeClassValue($value);

        // Escape if needed
        if ($escape) {
            $value = e($value);
        }

        // Merge and remove duplicates
        $merged = trim("$defaults $value");
        $classes = array_filter(explode(' ', $merged));

        return implode(' ', array_unique($classes));
    }

    /**
     * Merge style attributes.
     */
    protected function mergeStyles(string $defaults, mixed $value, bool $escape): string
    {
        $defaults = $this->normalizeStyleValue($defaults);
        $value = $this->normalizeStyleValue($value);

        // Escape if needed
        if ($escape) {
            $value = e($value);
        }

        // Join with semicolon separator, handling empty values
        $merged = trim("$defaults; $value", "; ");
        $styles = array_filter(explode(';', $merged), fn($style) => trim($style) !== '');

        return implode('; ', array_unique($styles));
    }

    /**
     * Merge a default attribute value.
     */
    protected function mergeDefault(mixed $defaults, mixed $value, bool $escape): mixed
    {
        if ($escape && $this->shouldEscapeAttributeValue(true, $value)) {
            $value = e($value);
        }

        // If defaults is empty, just return value
        if ($defaults === '' || $defaults === null) {
            return $value;
        }

        // If value is empty, just return defaults
        if ($value === '' || $value === null) {
            return $defaults;
        }

        return implode(' ', array_unique(array_filter([$defaults, $value])));
    }

    /**
     * Normalize a class value (array or string) to a string.
     */
    protected function normalizeClassValue(mixed $value): string
    {
        if (is_array($value)) {
            $classes = [];
            foreach ($value as $key => $val) {
                // Handle conditional classes: ['foo' => true, 'bar' => false]
                if (is_string($key)) {
                    if ($val) {
                        $classes[] = $key;
                    }
                } else {
                    // Handle simple array: ['foo', 'bar']
                    $val = (string) $val;
                    if ($val !== '') {
                        $classes[] = $val;
                    }
                }
            }
            return implode(' ', array_filter(array_map('trim', $classes)));
        }

        if (is_object($value) && $value instanceof Htmlable) {
            return trim($value->toHtml());
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return trim((string) $value);
        }

        return trim((string) $value);
    }

    /**
     * Normalize a style value (array or string) to a string.
     */
    protected function normalizeStyleValue(mixed $value): string
    {
        if (is_array($value)) {
            $styles = [];
            foreach ($value as $key => $val) {
                // Handle associative arrays: ['color' => 'red', 'font-size' => '12px']
                if (is_string($key)) {
                    if ($val !== null && $val !== false && $val !== '') {
                        $styles[] = "$key: $val";
                    }
                } else {
                    // Handle simple arrays: ['color: red', 'font-size: 12px']
                    $val = (string) $val;
                    if ($val !== '') {
                        $styles[] = $val;
                    }
                }
            }
            $styles = array_map(
                fn($style) => trim($style, '; '),
                array_filter($styles)
            );
            return implode('; ', $styles);
        }

        if (is_object($value) && $value instanceof Htmlable) {
            return trim($value->toHtml());
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return trim((string) $value);
        }

        $value = trim((string) $value);
        return $value;
    }

    /**
     * Determine if the value should be escaped.
     */
    protected function shouldEscapeAttributeValue(bool $escape, mixed $value): bool
    {
        if (!$escape) {
            return false;
        }

        // Don't escape null or booleans
        if ($value === null || is_bool($value)) {
            return false;
        }

        // Don't escape arrays (they'll be normalized first)
        if (is_array($value)) {
            return false;
        }

        // Don't escape Htmlable objects (they're already safe HTML)
        if (is_object($value) && $value instanceof Htmlable) {
            return false;
        }

        // Don't escape other objects
        if (is_object($value)) {
            return false;
        }

        return true;
    }

    /**
     * Set the underlying attributes.
     */
    public function setAttributes(array $attributes): static
    {
        if (isset($attributes['attributes']) && $attributes['attributes'] instanceof self) {
            return $attributes['attributes'];
        }

        return new static($attributes['attributes'] ?? []);
    }

    /**
     * Get all of the attributes.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Determine if the attributes are empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->attributes);
    }

    /**
     * Determine if the attributes are not empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Convert the attributes to their HTML representation.
     */
    public function toHtml(): string
    {
        return (string) $this;
    }

    /**
     * Get the string representation of the attributes.
     */
    public function __toString(): string
    {
        $attributes = [];

        foreach ($this->attributes as $key => $value) {
            // Skip false and null values
            if ($value === false || $value === null) {
                continue;
            }

            // Validate attribute key
            $key = $this->sanitizeAttributeKey($key);
            if (empty($key)) {
                continue;
            }

            // Boolean attributes (e.g., disabled, required, readonly)
            if ($value === true) {
                $attributes[] = $key;
                continue;
            }

            // Normalize class and style before converting to string
            if ($key === 'class') {
                $value = $this->normalizeClassValue($value);
            } elseif ($key === 'style') {
                $value = $this->normalizeStyleValue($value);
            }

            // Convert value to string
            $stringValue = $this->convertValueToString($value);

            // Skip empty string values for cleaner output
            if ($stringValue === '') {
                continue;
            }

            // Escape and format the attribute
            $attributes[] = sprintf('%s="%s"', $key, $this->escapeAttributeValue($stringValue));
        }

        return implode(' ', $attributes);
    }

    /**
     * Sanitize an attribute key to ensure it's valid HTML.
     */
    protected function sanitizeAttributeKey(mixed $key): string
    {
        // Convert to string and trim
        $key = trim((string) $key);

        // Remove any characters that aren't valid in HTML attribute names
        // Valid: letters, digits, hyphens, periods, colons, underscores, and @
        $sanitized = preg_replace('/[^a-zA-Z0-9\-_@:.]/', '', $key);

        return $sanitized !== null ? $sanitized : '';
    }

    /**
     * Convert a value to its string representation.
     */
    protected function convertValueToString(mixed $value): string
    {
        // Handle arrays (join with space)
        if (is_array($value)) {
            $values = array_map('strval', $value);
            $values = array_filter($values, fn($v) => $v !== '');
            return implode(' ', $values);
        }

        // Handle objects that implement Htmlable
        if (is_object($value) && $value instanceof Htmlable) {
            return $value->toHtml();
        }

        // Handle objects that implement __toString
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // Handle other objects (skip them)
        if (is_object($value)) {
            return '';
        }

        // Convert scalar values to string
        return (string) $value;
    }

    /**
     * Escape an attribute value for safe HTML output.
     */
    protected function escapeAttributeValue(string $value): string
    {
        // Use htmlspecialchars for proper HTML entity encoding
        // This handles ", &, <, > and ' properly
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /**
     * Implode the attributes into a single HTML ready string.
     */
    public function __invoke(array $defaults = []): string
    {
        return (string) $this->merge($defaults);
    }

    /**
     * Determine if the given offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    /**
     * Get the value at the given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    /**
     * Set the value at a given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    /**
     * Remove the value at the given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    /**
     * Get an iterator for the items.
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->attributes);
    }

    /**
     * Convert the attributes to an array.
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}