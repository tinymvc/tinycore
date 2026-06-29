<?php

namespace Spark\Http;

use Spark\Contracts\Http\InputContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Jsonable;
use Spark\Support\Collection;
use Spark\Support\Str;
use Spark\Support\Stringable;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function func_get_args;
use function is_array;
use function is_int;
use function is_string;
use function in_array;

/**
 * Class Sanitizer
 * 
 * Sanitizer class provides methods to sanitize and validate different data types.
 * It includes methods for emails, URLs, HTML, numbers, booleans, dates, and custom data arrays.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Input implements InputContract, Arrayable, Jsonable, \Stringable, \ArrayAccess, \IteratorAggregate
{
    use Macroable, Conditionable;

    /**
     * The data array to be sanitized.
     *
     * @var Collection
     */
    public Collection $data;

    /**
     * Constructs a new input instance with optional initial data.
     *
     * @param array|Request|Collection|Arrayable $data Key-value data array to be sanitized.
     */
    public function __construct(array|Request|Collection|Arrayable $data = [])
    {
        // Convert to Collection if not already
        if ($data instanceof Request) {
            $data = $data->query->merge($data->post)->merge($data->files);
        } elseif (!$data instanceof Collection) {
            if ($data instanceof Arrayable) {
                $data = $data->toArray(); // Convert Arrayable to array
            }
            $data = collect($data); // Convert array to Collection
        }

        $this->data = $data;
    }

    /**
     * Static factory method to create a new input instance with optional initial data.
     *
     * @param array|Request|Collection|Arrayable $data Key-value data array to be sanitized.
     * @return self Returns a new instance of the Input class with the provided data.
     */
    public static function make(array|Request|Collection|Arrayable $data = []): self
    {
        return new self($data);
    }

    /**
     * Sanitizes an email address.
     *
     * @param ?string $key Key in the data array to sanitize.
     * @return string|null Sanitized email or null if invalid.
     */
    public function email(?string $key = null): ?string
    {
        $key ??= 'email';
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        $sanitized = filter_var((string) $value, FILTER_SANITIZE_EMAIL);
        return $sanitized === false ? null : $sanitized;
    }

    /**
     * Sanitizes plain text, with optional HTML tag stripping.
     *
     * @param string $key Key in the data array to sanitize.
     * @param bool $stripTags Whether to strip HTML tags from the text.
     * @return string|null Sanitized text or null if invalid.
     */
    public function text(?string $key = null, bool $stripTags = true): ?string
    {
        $key ??= 'text';
        $value = $this->get($key);

        if ($value === null) {
            return null;
        }

        $sanitized = filter_var((string) $value, FILTER_UNSAFE_RAW);

        return $stripTags ? strip_tags((string) $sanitized) : (string) $sanitized;
    }

    /**
     * Sanitizes a string value.
     *
     * @param string $key Key in the data array to sanitize.
     * @param mixed $default Default value to return if key is not found.
     * @return string|null Sanitized string or null if invalid.
     */
    public function string(string $key, $default = null): ?string
    {
        $value = $this->get($key, $default);
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            $encoded = json_encode($value);
            return $encoded !== false ? $encoded : null;
        }

        return (string) $value;
    }

    /**
     * Escapes HTML special characters for safe output.
     *
     * @param ?string $key Key in the data array to sanitize.
     * @return string|null Sanitized HTML or null if invalid.
     */
    public function html(?string $key = null): ?string
    {
        $key ??= 'html';
        $value = $this->get($key);

        if ($value === null) {
            return null;
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitizes an integer value.
     *
     * @param ?string $key Key in the data array to sanitize.
     * @return int|null Sanitized integer or null if invalid.
     */
    public function number(?string $key = null): ?int
    {
        $key ??= 'number';
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        $sanitized = filter_var((string) $value, FILTER_SANITIZE_NUMBER_INT);

        return $sanitized === false || $sanitized === '' ? null : (int) $sanitized;
    }

    /**
     * Sanitizes a floating-point number.
     *
     * @param string $key Key in the data array to sanitize.
     * @return float|null Sanitized float or null if invalid.
     */
    public function float(?string $key = null): ?float
    {
        $key ??= 'float';
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        $sanitized = filter_var((string) $value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND | FILTER_FLAG_ALLOW_SCIENTIFIC);

        return $sanitized === false || $sanitized === '' ? null : (float) $sanitized;
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
    public function url(?string $key = null): ?string
    {
        $key ??= 'url';
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        $sanitized = filter_var((string) $value, FILTER_SANITIZE_URL);
        return $sanitized === false || $sanitized === '' ? null : $sanitized;
    }

    /**
     * Validates an IP address.
     *
     * @param ?string $key Key in the data array to validate.
     * @return string|null Valid IP address or null if invalid.
     */
    public function ip(?string $key = null): ?string
    {
        $key ??= 'ip';
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var((string) $value, FILTER_VALIDATE_IP) ?: null;
    }

    /**
     * Sanitizes a string to contain only alphabetic characters.
     *
     * @param string $key Key in the data array to sanitize.
     * @return string|null Sanitized alphabetic string or null if invalid.
     */
    public function alpha(string $key): ?string
    {
        $value = $this->get($key);
        if ($value === null || $value === '')
            return null;

        $sanitized = preg_replace('/[^a-zA-Z]/', '', (string) $value);
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
        if ($value === null || $value === '')
            return null;

        $sanitized = preg_replace('/[^a-zA-Z0-9]/', '', (string) $value);
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
        if ($value === null || $value === '')
            return null;

        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value);
        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Sanitizes a string to contain only digits.
     *
     * @param ?string $key Key in the data array to sanitize.
     * @return string|null Sanitized digit string or null if invalid.
     */
    public function digits(?string $key = null): ?string
    {
        $key ??= 'digits';
        $value = $this->get($key);
        if ($value === null || $value === '')
            return null;

        $sanitized = preg_replace('/[^0-9]/', '', (string) $value);
        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Sanitizes a phone number by removing non-digit characters.
     *
     * @param ?string $key Key in the data array to sanitize.
     * @param bool $keepPlus Whether to keep the plus sign for international numbers.
     * @return string|null Sanitized phone number or null if invalid.
     */
    public function phone(?string $key = null, bool $keepPlus = true): ?string
    {
        $key ??= 'phone';
        $value = $this->get($key);
        if ($value === null || $value === '')
            return null;

        $pattern = $keepPlus ? '/[^0-9+]/' : '/[^0-9]/';
        $sanitized = preg_replace($pattern, '', (string) $value);
        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Sanitizes a date string and optionally formats it.
     *
     * @param ?string $key Key in the data array to sanitize.
     * @param string $format Output date format (default: 'Y-m-d').
     * @return string|null Sanitized date or null if invalid.
     */
    public function date(?string $key = null, string $format = 'Y-m-d'): ?string
    {
        $key ??= 'date';
        $value = $this->get($key);
        if ($value === null || $value === '')
            return null;

        $timestamp = strtotime((string) $value);
        return $timestamp !== false ? date($format, $timestamp) : null;
    }

    /**
     * Sanitizes a JSON string and optionally decodes it.
     *
     * @param ?string $key Key in the data array to sanitize.
     * @param bool $decode Whether to decode the JSON to array.
     * @return string|array|null Sanitized JSON or decoded array, null if invalid.
     */
    public function json(?string $key = null, bool $decode = false): string|array|null
    {
        $key ??= 'json';
        $value = $this->get($key);
        if ($value === null || $value === '')
            return null;

        $decoded = json_decode((string) $value, true);
        if (json_last_error() !== JSON_ERROR_NONE)
            return null;

        return $decode ? $decoded : $value;
    }

    /**
     * Sanitizes an array by filtering out empty values and optionally applying a callback.
     *
     * @param ?string $key Key in the data array to sanitize.
     * @param callable|null $callback Optional callback to apply to each array element.
     * @param bool $removeEmpty Whether to remove empty values.
     * @return array|null Sanitized array or null if invalid.
     */
    public function array(?string $key = null, ?callable $callback = null, bool $removeEmpty = true): ?array
    {
        $key ??= 'array';
        $value = $this->get($key);
        if (!is_array($value)) {
            $value = arr_from_set($value);
            if (!is_array($value)) {
                return null;
            }
        }

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
     * @param ?string $key Key in the data array to sanitize.
     * @return array|null Sanitized file array or null if invalid.
     */
    public function file(?string $key = null): ?array
    {
        $key ??= 'file';
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
     * @param ?string $key Key in the data array to sanitize.
     * @param bool $hash Whether to hash the password.
     * @param array $options Password hashing options.
     * @return string|null Sanitized password or null if invalid.
     */
    public function password(?string $key = null, bool $hash = false, array $options = []): ?string
    {
        $key ??= 'password';
        $value = $this->get($key);
        if ($value === null)
            return null;

        $sanitized = trim((string) $value);

        if ($hash) {
            if (!empty($options)) {
                hashing()->setPasswordOptions($options);
            }

            return hashing()->hashPassword($sanitized);
        }

        return $sanitized;
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
        if ($value === null)
            return null;

        if (empty($allowedTags)) {
            return strip_tags((string) $value);
        }

        $allowedTagsString = '<' . implode('><', $allowedTags) . '>';
        return strip_tags((string) $value, $allowedTagsString);
    }

    /**
     * Collects values from the data array into a Spark\Support\Collection.
     * 
     * @template TKey of array-key
     *
     * @template-covariant TValue
     *
     * @param string $key
     * @return \Spark\Support\Collection<TKey, TValue>
     */
    public function collect(string $key): Collection
    {
        $value = $this->get($key);
        if (is_array($value)) {
            return new Collection($value);
        }

        return new Collection([$value]);
    }

    /**
     * Sets a key-value pair in the sanitizer data array.
     *
     * @param string $key Key in the data array.
     * @param mixed $value Value to set.
     */
    public function set(string $key, mixed $value): void
    {
        $this->data->put($key, $value);
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
        return $this->data->get($key, $default);
    }

    /**
     * Checks if a key exists in the sanitizer data array.
     *
     * @param string $key Key to check.
     * @return bool True if key exists, false otherwise.
     */
    public function has(string $key): bool
    {
        return $this->data->has($key);
    }

    /**
     * Checks if any of the specified keys exist in the sanitizer data array.
     *
     * @param array|string $keys Keys to check.
     * @return bool True if any key exists, false otherwise.
     */
    public function hasAny(array|string $keys): bool
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if all of the specified keys exist in the sanitizer data array.
     *
     * @param array|string $keys Keys to check.
     * @return bool True if all keys exist, false otherwise.
     */
    public function hasAll(array|string $keys): bool
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        foreach ($keys as $key) {
            if (!$this->has($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Retrieves all sanitized data as an associative array.
     *
     * @return array All key-value pairs in the sanitizer data array.
     */
    public function all(): array
    {
        return $this->data->all();
    }

    /**
     * Checks if the sanitizer data array is empty.
     *
     * @return bool True if empty, false otherwise.
     */
    public function isEmpty(): bool
    {
        return $this->data->isEmpty();
    }

    /**
     * Checks if the sanitizer data array is not empty.
     *
     * @return bool True if not empty, false otherwise.
     */
    public function isNotEmpty(): bool
    {
        return $this->data->isNotEmpty();
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
     * @param string $name name to unset.
     * @return void
     */
    public function offsetExists($name): bool
    {
        return $this->has($name);
    }

    /**
     * Magic method to unset a key-value pair in the sanitizer data array.
     *
     * @param string $name name to unset.
     * @return void
     */
    public function offsetUnset($name): void
    {
        $this->data->forget($name);
    }

    /**
     * Magic method to retrieve a value from the sanitizer data array.
     *
     * @param string $name Key to retrieve.
     * @return mixed The value associated with the key, or null if not found.
     */
    public function offsetGet($name): mixed
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
    public function offsetSet($name, $value): void
    {
        $this->set($name, $value);
    }

    /**
     *  Converts the sanitizer data array to an associative array based on the provided configuration.
     *
     *  @param array $config An associative array where keys are the data keys and values are the types to sanitize.
     *  @return array An associative array with sanitized values based on the provided configuration.
     *  Supported types: 'email', 'text', 'html', 'number', 'float', 'boolean', 'url', 'ip', 'alpha', 'alpha_num',
     *                   'alpha_dash', 'digits', 'phone', 'slug', 'string', 'date', 'json', 'array', 'file',
     *                   'password', 'safe'.
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

        foreach ($config as $field => $type) {
            if (is_int($field)) {
                $field = (string) $type;
                $type = 'string';
            }

            // If the type is an array, use the first element as the type.
            if (is_array($type)) {
                $type = $type[0] ?? null;
            }

            // Pick the first item when a Laravel-like pipe string is passed.
            if (is_string($type) && str_contains($type, '|')) {
                $type = array_values(array_filter(array_map('trim', explode('|', $type))));
                $type = $type[0] ?? 'string';
            }

            if (
                !is_string($type) || !in_array($type, [
                    'email',
                    'text',
                    'html',
                    'number',
                    'float',
                    'boolean',
                    'url',
                    'ip',
                    'alpha',
                    'alpha_num',
                    'alpha_dash',
                    'digits',
                    'phone',
                    'slug',
                    'string',
                    'date',
                    'json',
                    'array',
                    'file',
                    'password',
                    'safe'
                ], true)
            ) {
                $type = 'string';
            }

            $result[$field] = match ($type) {
                'email' => $this->email($field),
                'text' => $this->text($field),
                'html' => $this->html($field),
                'number' => $this->number($field),
                'float' => $this->float($field),
                'boolean' => $this->boolean($field),
                'url' => $this->url($field),
                'ip' => $this->ip($field),
                'alpha' => $this->alpha($field),
                'alpha_num' => $this->alphaNum($field),
                'alpha_dash' => $this->alphaDash($field),
                'digits' => $this->digits($field),
                'phone' => $this->phone($field),
                'slug' => $this->slug($field),
                'string' => $this->string($field),
                'date' => $this->date($field),
                'json' => $this->json($field),
                'array' => $this->array($field),
                'file' => $this->file($field),
                'password' => $this->password($field),
                'safe' => $this->safe($field),
                default => $this->get($field),
            };
        }

        return $result;
    }

    /**
     * Filters the sanitizer data array to include only specified keys.
     *
     * @param array|string $keys Keys to include in the filtered data.
     * @return self Returns a new instance with filtered data.
     */
    public function only(array|string $keys): self
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        return new self($this->data->only($keys));
    }

    /**
     * Filters the sanitizer data array to exclude specified keys.
     *
     * @param array|string $keys Keys to exclude from the filtered data.
     * @return self Returns a new instance with filtered data.
     */
    public function except(array|string $keys): self
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        return new self($this->data->except($keys));
    }

    /**
     * Maps the sanitizer data array using a callback function.
     *
     * @param callable $callback Callback function to apply to each element.
     * @return self Returns a new instance with mapped data.
     */
    public function map(callable $callback): self
    {
        return new self($this->data->map($callback));
    }

    /**
     * Filters the sanitizer data array using a callback function.
     *
     * @param null|callable $callback Callback function to filter elements.
     * @return self Returns a new instance with filtered data.
     */
    public function filter(null|callable $callback = null): self
    {
        return new self($this->data->filter($callback));
    }

    /**
     * Converts the sanitizer data array to a Spark\Support\Collection.
     *
     * @return \Spark\Support\Collection The collection containing sanitized data.
     */
    public function toCollection(): Collection
    {
        return $this->data;
    }

    /**
     * Sanitizes a string into a URL-friendly slug.
     *
     * @param ?string $key Key in the data array to sanitize.
     * @param string $separator Separator character.
     * @param string $language Language for transliteration.
     * @param array $dictionary Optional replacements for transliteration.
     * @return string|null
     */
    public function slug(?string $key = null, string $separator = '-', string $language = 'en', array $dictionary = ['@' => 'at']): ?string
    {
        $key ??= 'slug';

        $value = $this->get($key);
        if ($value === null) {
            return null;
        }

        return Str::slug((string) $value, $separator, $language, $dictionary);
    }

    /**
     * Converts the sanitizer data array to a Spark\Support\Stringable instance.
     *
     * @param string $key Key in the data array to convert to Stringable.
     * @param string $default Default value if key is not found.
     * @return \Spark\Support\Stringable The Stringable instance containing the sanitized string.
     */
    public function str(string $key, string $default = ''): \Spark\Support\Stringable
    {
        return new Stringable(
            (string) $this->data->get($key, $default)
        );
    }

    /**
     * Converts the sanitizer data array to an associative array.
     *
     * @return array The sanitizer data array.
     */
    public function toArray(): array
    {
        return $this->data->toArray();
    }

    /**
     * Create a copy of the sanitizer instance.
     *
     * @return self A new instance that is a copy of the current instance.
     */
    public function copy(): self
    {
        return clone $this;
    }

    /**
     * Converts the sanitizer data array to a JSON string.
     *
     * @param int $options JSON encoding options.
     * @return string The JSON representation of the sanitizer data.
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Get an iterator for the items.
     * 
     * This method allows the model to be iterated over like an array.
     * 
     * @template TKey of array-key
     *
     * @template-covariant TValue
     *
     * @implements \ArrayAccess<TKey, TValue>
     *
     * @return \ArrayIterator<TKey, TValue>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->toArray());
    }

    /**
     *  Converts the sanitizer data to a string representation.
     * 
     *  @return string The string representation of the first sanitized text value.
     *  If no text is found, returns an empty string.
     */
    public function __toString(): string
    {
        $first = $this->data->first();

        return match (true) {
            $first === null => '',
            is_array($first), is_object($first) => json_encode($first) ?: '',
            default => (string) $first,
        };
    }
}
