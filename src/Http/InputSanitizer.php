<?php

namespace Spark\Http;

use ArrayAccess;
use Spark\Contracts\Http\InputSanitizerContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Support\Traits\Macroable;

/**
 * Class Sanitizer
 * 
 * Sanitizer class provides methods to sanitize and validate different data types.
 * It includes methods for emails, URLs, HTML, numbers, booleans, dates, and custom data arrays.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class InputSanitizer implements InputSanitizerContract, ArrayAccess, Arrayable
{
    use Macroable;

    /**
     * Constructs a new sanitizer instance with optional initial data.
     *
     * @param array $data Key-value data array to be sanitized.
     */
    public function __construct(private array $data = [])
    {
    }

    /**
     * Sets the data array to be sanitized.
     *
     * @param array $data Key-value data array to be set.
     * @return self Returns the current instance for method chaining.
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Sanitizes an email address.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized email or null if invalid.
     */
    public function email(string $key): ?string
    {
        return filter_var($this->get($key), FILTER_SANITIZE_EMAIL) ?: null;
    }

    /**
     * Sanitizes plain text, with optional HTML tag stripping.
     *
     * @param string $key Key in the data array to sanitize.
     * @param bool $stripTags Whether to strip HTML tags from the text.
     * @return string|null Sanitized text or null if invalid.
     */
    public function text(string $key, bool $stripTags = true): ?string
    {
        $value = filter_var($this->get($key), FILTER_UNSAFE_RAW);
        return $stripTags && $value ? strip_tags($value) : $value;
    }

    /**
     * Escapes HTML special characters for safe output.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized HTML or null if invalid.
     */
    public function html(string $key): ?string
    {
        return htmlspecialchars($this->get($key), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitizes an integer value.
     *
     * @param string $key Key in the data array to sanitize.
     * @return int|null Sanitized integer or null if invalid.
     */
    public function number(string $key): ?int
    {
        return filter_var($this->get($key), FILTER_SANITIZE_NUMBER_INT) ?: null;
    }

    /**
     * Sanitizes a floating-point number.
     *
     * @param string $key Key in the data array to sanitize.
     * @return float|null Sanitized float or null if invalid.
     */
    public function float(string $key): ?float
    {
        return filter_var($this->get($key), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) ?: null;
    }

    /**
     * Sanitizes a boolean value.
     *
     * @param string $key Key in the data array to validate.
     * @return bool|null Sanitized boolean or null if invalid.
     */
    public function boolean(string $key): ?bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Sanitizes a URL.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized URL or null if invalid.
     */
    public function url(string $key): ?string
    {
        return filter_var($this->get($key), FILTER_SANITIZE_URL) ?: null;
    }

    /**
     * Validates an IP address.
     *
     * @param string $key Key in the data array to validate.
     * @return string|null Valid IP address or null if invalid.
     */
    public function ip(string $key): ?string
    {
        return filter_var($this->get($key), FILTER_VALIDATE_IP) ?: null;
    }

    /**
     * Sets a key-value pair in the sanitizer data array.
     *
     * @param string $key Key in the data array.
     * @param mixed $value Value to set.
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Retrieves the value of a key from the data array or returns a default value.
     *
     * @param string $key Key to retrieve.
     * @param mixed $default Default value if key does not exist.
     * @return mixed Retrieved value or default.
     */
    public function get(string $key, $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Checks if a key exists in the sanitizer data array.
     *
     * @param string $key Key to check.
     * @return bool True if key exists, false otherwise.
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Retrieves all sanitized data as an associative array.
     *
     * @return array All key-value pairs in the sanitizer data array.
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Magic method to retrieve a value from the sanitizer data array.
     *
     * @param string $name Key to retrieve.
     * @return mixed The value associated with the key, or null if not found.
     */
    public function __get($name): mixed
    {
        return $this->get($name);
    }

    /**
     * Magic method to set a key-value pair in the sanitizer data array.
     *
     * @param string $name Key to set.
     * @param mixed $value Value to set.
     * @return void
     */
    public function __set($name, $value): void
    {
        $this->set($name, $value);
    }

    /**
     * Magic method to check if a key is set in the sanitizer data array.
     *
     * @param string $name Key to check.
     * @return bool True if key is set, false otherwise.
     */
    public function __isset($name): bool
    {
        return $this->has($name);
    }

    /**
     * Magic method to unset a key-value pair in the sanitizer data array.
     *
     * @param string $name Key to unset.
     * @return void
     */
    public function offsetExists($key): bool
    {
        return $this->has($key);
    }

    /**
     * Magic method to unset a key-value pair in the sanitizer data array.
     *
     * @param string $name Key to unset.
     * @return void
     */
    public function offsetUnset($key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Magic method to retrieve a value from the sanitizer data array.
     *
     * @param string $key Key to retrieve.
     * @return mixed The value associated with the key, or null if not found.
     */
    public function offsetGet($key): mixed
    {
        return $this->get($key);
    }

    /**
     * Magic method to set a key-value pair in the sanitizer data array.
     *
     * @param string $key Key to set.
     * @param mixed $value Value to set.
     * @return void
     */
    public function offsetSet($key, $value): void
    {
        $this->set($key, $value);
    }

    /**
     * Converts the sanitizer data array to an associative array.
     *
     * @return array The sanitizer data array.
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
