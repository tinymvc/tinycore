<?php

namespace Spark\Http;

use ArrayAccess;
use Spark\Contracts\Http\InputSanitizerContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Support\Str;
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

    // NEW SANITIZATION METHODS

    /**
     * Sanitizes a string to contain only alphabetic characters.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized alphabetic string or null if invalid.
     */
    public function alpha(string $key): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $sanitized = preg_replace('/[^a-zA-Z]/', '', $value);
        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Sanitizes a string to contain only alphanumeric characters.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized alphanumeric string or null if invalid.
     */
    public function alphaNum(string $key): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $sanitized = preg_replace('/[^a-zA-Z0-9]/', '', $value);
        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Sanitizes a string to contain only alphanumeric characters, dashes, and underscores.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized string or null if invalid.
     */
    public function alphaDash(string $key): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Sanitizes a string to contain only digits.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized digit string or null if invalid.
     */
    public function digits(string $key): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $sanitized = preg_replace('/[^0-9]/', '', $value);
        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Sanitizes a phone number by removing non-digit characters.
     *
     * @param string $key Key in the data array to sanitize.
     * @param bool $keepPlus Whether to keep the plus sign for international numbers.
     * @return string|null Sanitized phone number or null if invalid.
     */
    public function phone(string $key, bool $keepPlus = true): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $pattern = $keepPlus ? '/[^0-9+]/' : '/[^0-9]/';
        $sanitized = preg_replace($pattern, '', $value);
        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Sanitizes a slug by converting to lowercase and replacing spaces/special chars with dashes.
     *
     * @param string $key Key in the data array to sanitize.
     * @param string $separator Character to use as separator (default: '-').
     * @return string|null Sanitized slug or null if invalid.
     */
    public function slug(string $key, string $separator = '-'): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        return Str::slug($value, $separator);
    }

    /**
     * Sanitizes a string by trimming whitespace and optionally converting case.
     *
     * @param string $key Key in the data array to sanitize.
     * @param string $case Case conversion: 'lower', 'upper', 'title', or null for no conversion.
     * @return string|null Sanitized string or null if invalid.
     */
    public function string(string $key, ?string $case = null): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $sanitized = trim($value);

        return match ($case) {
            'lower' => strtolower($sanitized),
            'upper' => strtoupper($sanitized),
            'title' => ucwords($sanitized),
            default => $sanitized
        };
    }

    /**
     * Sanitizes a date string and optionally formats it.
     *
     * @param string $key Key in the data array to sanitize.
     * @param string $format Output date format (default: 'Y-m-d').
     * @return string|null Sanitized date or null if invalid.
     */
    public function date(string $key, string $format = 'Y-m-d'): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $timestamp = strtotime($value);
        return $timestamp !== false ? date($format, $timestamp) : null;
    }

    /**
     * Sanitizes a JSON string and optionally decodes it.
     *
     * @param string $key Key in the data array to sanitize.
     * @param bool $decode Whether to decode the JSON to array.
     * @return string|array|null Sanitized JSON or decoded array, null if invalid.
     */
    public function json(string $key, bool $decode = false): string|array|null
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE)
            return null;

        return $decode ? $decoded : $value;
    }

    /**
     * Sanitizes an array by filtering out empty values and optionally applying a callback.
     *
     * @param string $key Key in the data array to sanitize.
     * @param callable|null $callback Optional callback to apply to each array element.
     * @param bool $removeEmpty Whether to remove empty values.
     * @return array|null Sanitized array or null if invalid.
     */
    public function array(string $key, ?callable $callback = null, bool $removeEmpty = true): ?array
    {
        $value = $this->get($key);
        if (!is_array($value))
            return null;

        if ($removeEmpty) {
            $value = array_filter($value, fn($item) => !empty($item) || $item === 0 || $item === '0');
        }

        if ($callback) {
            $value = array_map($callback, $value);
        }

        return $value;
    }

    /**
     * Sanitizes a file upload array.
     *
     * @param string $key Key in the data array to sanitize.
     * @return array|null Sanitized file array or null if invalid.
     */
    public function file(string $key): ?array
    {
        $value = $this->get($key);
        if (!is_array($value) || !isset($value['tmp_name']))
            return null;

        // Basic file sanitization
        return [
            'name' => filter_var($value['name'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'type' => filter_var($value['type'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'tmp_name' => $value['tmp_name'],
            'error' => (int) ($value['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($value['size'] ?? 0)
        ];
    }

    /**
     * Sanitizes a password by trimming and optionally hashing.
     *
     * @param string $key Key in the data array to sanitize.
     * @param bool $hash Whether to hash the password.
     * @param array $options Password hashing options.
     * @return string|null Sanitized password or null if invalid.
     */
    public function password(string $key, bool $hash = false, array $options = []): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $sanitized = trim($value);

        if ($hash) {
            if (!empty($options)) {
                hashing()->setPasswordOptions($options);
            }

            return hashing()->hashPassword($sanitized);
        }

        return $sanitized;
    }

    /**
     * Sanitizes a UUID string.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized UUID or null if invalid.
     */
    public function uuid(string $key): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        $sanitized = strtolower(trim($value));

        // Validate UUID format
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $sanitized)) {
            return $sanitized;
        }

        return null;
    }

    /**
     * Removes or escapes potentially dangerous content from text.
     *
     * @param string $key Key in the data array to sanitize.
     * @param array $allowedTags Array of allowed HTML tags.
     * @return string|null Sanitized content or null if invalid.
     */
    public function safe(string $key, array $allowedTags = []): ?string
    {
        $value = $this->get($key);
        if (!$value)
            return null;

        if (empty($allowedTags)) {
            return strip_tags($value);
        }

        $allowedTagsString = '<' . implode('><', $allowedTags) . '>';
        return strip_tags($value, $allowedTagsString);
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
     *  Converts the sanitizer data array to an associative array based on the provided configuration.
     *
     *  @param array $config An associative array where keys are the data keys and values are the types to sanitize.
     *  @return array An associative array with sanitized values based on the provided configuration.
     *  Supported types: 'email', 'text', 'html', 'number', 'float', 'boolean', 'url', 'ip', 'alpha', 'alpha_num', 
     *                   'alpha_dash', 'digits', 'phone', 'slug', 'string', 'date', 'json', 'array', 'file', 
     *                   'password', 'uuid', 'safe'.
     *  If a type is not recognized, it defaults to returning the raw value.
     *  
     *  Example:
     *  $config = [
     *      'user_email' => 'email',
     *      'user_name' => 'text',
     *      'user_age' => 'number',
     *      'username' => 'alpha_dash',
     *      'phone_number' => 'phone',
     *  ];
     *  $sanitizedData = $sanitizer->to($config);
     *
     *  This will return an associative array with sanitized values for each key based on the specified type.
     *  If a key does not exist in the sanitizer data, it will return null for that key.
     *  If a type is not recognized, it will return the raw value for that key.
     */
    public function to(array $config): array
    {
        $result = [];

        foreach ($config as $key => $type) {
            //  If the type is not a string, assume the key itself is the type
            //  This allows for flexibility in specifying types or using the key as the type.
            if (is_int($type)) {
                $type = $key;
            }

            $result[$key] = match ($type) {
                'email' => $this->email($key),
                'text' => $this->text($key),
                'html' => $this->html($key),
                'number' => $this->number($key),
                'float' => $this->float($key),
                'boolean' => $this->boolean($key),
                'url' => $this->url($key),
                'ip' => $this->ip($key),
                'alpha' => $this->alpha($key),
                'alpha_num' => $this->alphaNum($key),
                'alpha_dash' => $this->alphaDash($key),
                'digits' => $this->digits($key),
                'phone' => $this->phone($key),
                'slug' => $this->slug($key),
                'string' => $this->string($key),
                'date' => $this->date($key),
                'json' => $this->json($key),
                'array' => $this->array($key),
                'file' => $this->file($key),
                'password' => $this->password($key),
                'uuid' => $this->uuid($key),
                'safe' => $this->safe($key),
                default => $this->get($key),
            };
        }

        return $result;
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

    /**
     *  Converts the sanitizer data to a string representation.
     * 
     *  @return string The string representation of the first sanitized text value.
     *  If no text is found, returns an empty string.
     */
    public function __toString(): string
    {
        return $this->text(array_key_first($this->data), true) ?? '';
    }
}