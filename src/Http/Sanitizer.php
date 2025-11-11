<?php

namespace Spark\Http;

use Spark\Contracts\Http\SanitizerContract;
use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Jsonable;
use Spark\Support\Collection;
use Spark\Support\Str;
use Spark\Support\Traits\Macroable;

/**
 * Class Sanitizer
 * 
 * Sanitizer class provides methods to sanitize and validate different data types.
 * It includes methods for emails, URLs, HTML, numbers, booleans, dates, and custom data arrays.
 * 
 * @method string after($field, $search)
 * @method string afterLast($field, $search)
 * @method string ascii($field, $language = 'en')
 * @method string transliterate($field, $unknown = '?', $strict = false)
 * @method string before($field, $search)
 * @method string beforeLast($field, $search)
 * @method string between($field, $from, $to)
 * @method string betweenFirst($field, $from, $to)
 * @method string camel($field)
 * @method string|false charAt($field, $index)
 * @method string chopStart($field, $needle)
 * @method string chopEnd($field, $needle)
 * @method bool contains($field, $needles, $ignoreCase = false)
 * @method bool containsAll($field, $needles, $ignoreCase = false)
 * @method bool doesntContain($field, $needles, $ignoreCase = false)
 * @method string convertCase($field, int $mode = MB_CASE_FOLD, ?string $encoding = 'UTF-8')
 * @method string deduplicate($field, $character = ' ')
 * @method bool endsWith($field, $needles)
 * @method string|null excerpt($field, $phrase = '', $options = [])
 * @method string finish($field, $cap)
 * @method string wrap($field, $before, $after = null)
 * @method string unwrap($field, $before, $after = null)
 * @method bool is($pattern, $field, $ignoreCase = false)
 * @method bool isAscii($field)
 * @method bool isJson($field)
 * @method bool isUrl($field, array $protocols = [])
 * @method bool isUuid($field, $version = null)
 * @method bool isUlid($field)
 * @method string kebab($field)
 * @method int length($field, $encoding = null)
 * @method string limit($field, $limit = 100, $end = '...', $preserveWords = false)
 * @method string lower($field)
 * @method string words($field, $words = 100, $end = '...')
 * @method string markdown($field, array $options = [], array $extensions = [])
 * @method string inlineMarkdown($field, array $options = [], array $extensions = [])
 * @method string mask($field, $character, $index, $length = null, $encoding = 'UTF-8')
 * @method string match($pattern, $field)
 * @method bool isMatch($pattern, $field)
 * @method \Spark\Support\Collection matchAll($pattern, $field)
 * @method string numbers($field)
 * @method string padBoth($field, $length, $pad = ' ')
 * @method string padLeft($field, $length, $pad = ' ')
 * @method string padRight($field, $length, $pad = ' ')
 * @method array parseCallback($field, $default = null)
 * @method string plural($field, $count = 2)
 * @method string pluralStudly($field, $count = 2)
 * @method string pluralPascal($field, $count = 2)
 * @method int|false position($field, $needle, $offset = 0, $encoding = null)
 * @method string repeat($field, $times)
 * @method string replaceArray($search, $replace, $field)
 * @method string|string[] replace($search, $replace, $field, $caseSensitive = true)
 * @method string replaceFirst($search, $replace, $field)
 * @method string replaceStart($search, $replace, $field)
 * @method string replaceLast($search, $replace, $field)
 * @method string replaceEnd($search, $replace, $field)
 * @method string|string[]|null replaceMatches($pattern, $replace, $field, $limit = -1)
 * @method string remove($search, $field, $caseSensitive = true)
 * @method string reverse($field)
 * @method string start($field, $prefix)
 * @method string upper($field)
 * @method string title($field)
 * @method string headline($field)
 * @method string apa($field)
 * @method string singular($field)
 * @method string slug($field, $separator = '-', $language = 'en', $dictionary = ['@' => 'at'])
 * @method string snake($field, $delimiter = '_')
 * @method string trim($field, $charlist = null)
 * @method string ltrim($field, $charlist = null)
 * @method string rtrim($field, $charlist = null)
 * @method string squish($field)
 * @method bool startsWith($field, $needles)
 * @method string studly($field)
 * @method string pascal($field)
 * @method string substr($field, $start, $length = null, $encoding = 'UTF-8')
 * @method int substrCount($field, $needle, $offset = 0, $length = null)
 * @method string|string[] substrReplace($field, $replace, $offset = 0, $length = null)
 * @method string swap(array $map, $field)
 * @method string take($field, int $limit)
 * @method string toBase64($field)
 * @method string|false fromBase64($field, $strict = false)
 * @method string lcfirst($field)
 * @method string ucfirst($field)
 * @method string[] ucsplit($field)
 * @method int wordCount($field, $characters = null)
 * @method string wordWrap($field, $characters = 75, $break = "\n", $cutLongWords = false)
 *
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Sanitizer implements SanitizerContract, Arrayable, Jsonable, \Stringable, \ArrayAccess, \IteratorAggregate
{
    use Macroable {
        __call as macroCall;
    }

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
     * @param ?string $key Key in the data array to sanitize.
     * @return string|null Sanitized email or null if invalid.
     */
    public function email(?string $key = null): ?string
    {
        $key ??= 'email';
        return filter_var($this->get($key), FILTER_SANITIZE_EMAIL) ?: null;
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
        $value = filter_var($this->get($key), FILTER_UNSAFE_RAW);
        return $stripTags && $value ? strip_tags($value) : $value;
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
        if ($value === null || is_string($value)) {
            return $value;
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            return json_encode($value);
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
        return htmlspecialchars($this->get($key), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
    public function url(?string $key = null): ?string
    {
        $key ??= 'url';
        return filter_var($this->get($key), FILTER_SANITIZE_URL) ?: null;
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
        return filter_var($this->get($key), FILTER_VALIDATE_IP) ?: null;
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
     * @param ?string $key Key in the data array to sanitize.
     * @return string|null Sanitized digit string or null if invalid.
     */
    public function digits(?string $key = null): ?string
    {
        $key ??= 'digits';
        $value = $this->get($key);
        if (!$value)
            return null;

        $sanitized = preg_replace('/[^0-9]/', '', $value);
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
        if (!$value)
            return null;

        $pattern = $keepPlus ? '/[^0-9+]/' : '/[^0-9]/';
        $sanitized = preg_replace($pattern, '', $value);
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
        if (!$value)
            return null;

        $timestamp = strtotime($value);
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
            // Check if the type is a string with multiple options
            // If so, split it into an array of types
            if (is_string($type) && str_contains($type, '|')) {
                $type = explode('|', $type);
            }

            // If the type is an array, use the first element as the type
            if (is_array($type)) {
                $type = $type[0] ?? null;
            }

            //  If the type is not a string, assume the key itself is the type
            //  This allows for flexibility in specifying types or using the key as the type.
            if (is_int($field)) {
                $field = $type;
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
     * @param array|string ...$keys Keys to include in the filtered data.
     * @return self Returns a new instance with filtered data.
     */
    public function only(array|string ...$keys): self
    {
        $keys = is_array($keys[0]) ? $keys[0] : $keys;
        $filteredData = array_intersect_key($this->data, array_flip($keys));
        return new self($filteredData);
    }

    /**
     * Filters the sanitizer data array to exclude specified keys.
     *
     * @param array|string ...$keys Keys to exclude from the filtered data.
     * @return self Returns a new instance with filtered data.
     */
    public function except(array|string ...$keys): self
    {
        $keys = is_array($keys[0]) ? $keys[0] : $keys;
        $filteredData = array_diff_key($this->data, array_flip($keys));
        return new self($filteredData);
    }

    /**
     * Maps the sanitizer data array using a callback function.
     *
     * @param callable $callback Callback function to apply to each element.
     * @return self Returns a new instance with mapped data.
     */
    public function map(callable $callback): self
    {
        $mappedData = array_map($callback, $this->data);
        return new self($mappedData);
    }

    /**
     * Filters the sanitizer data array using a callback function.
     *
     * @param null|callable $callback Callback function to filter elements.
     * @return self Returns a new instance with filtered data.
     */
    public function filter(null|callable $callback = null): self
    {
        $filteredData = array_filter($this->data, $callback);
        return new self($filteredData);
    }

    /**
     * Converts the sanitizer data array to a Spark\Support\Collection.
     *
     * @return \Spark\Support\Collection The collection containing sanitized data.
     */
    public function toCollection(): Collection
    {
        return new Collection($this->data);
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
        return $this->text(array_key_first($this->data), true) ?? '';
    }

    /**
     * Handles dynamic method calls to the query instance.
     *
     * @param string $name The method name.
     * @param array $arguments The method arguments.
     * @return mixed The result of the query method call.
     */
    public function __call($name, $arguments)
    {
        if (static::hasMacro($name)) {
            return $this->macroCall($name, $arguments);
        }

        return Str::$name(strval($this->text($arguments[0])), ...array_slice($arguments, 1));
    }
}
