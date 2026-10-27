<?php

namespace Spark\Http\Validation\Concerns;

use Spark\Support\Str;
use function array_slice;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function str_replace;
use function strtolower;

trait FormatsMessages
{
    /**
     * @var array $errorMessages Custom error messages for validation rules.
     */
    private static array $errorMessages = [];

    /**
     * Sets custom error messages for validation rules.
     *
     * @param array $messages Associative array of rule names and their custom error messages.
     * @return void
     */
    public static function setErrorMessages(array $messages): void
    {
        self::$errorMessages = $messages;
    }

    /**
     * Merges custom error messages with existing ones.
     *
     * @param array $messages Associative array of rule names and their custom error messages to merge.
     * @return void
     */
    public static function mergeErrorMessages(array $messages): void
    {
        self::$errorMessages = [...self::$errorMessages, ...$messages];
    }

    /**
     * Sets a custom error message for a specific validation rule.
     *
     * @param string $rule Validation rule name.
     * @param array|string $message Custom error message for the rule.
     * @return void
     */
    public static function setErrorMessage(string $rule, array|string $message): void
    {
        self::$errorMessages[$rule] = $message;
    }

    /**
     * Adds an error message for a failed validation rule.
     *
     * @param string $field Field name that failed validation.
     * @param string $rule Validation rule that failed.
     * @param array $params Parameters for the rule, if any.
     * @param mixed $value The value that failed validation.
     * @return void
     */
    protected function addError(string $field, string $rule, array $params = [], mixed $value = null): void
    {
        $field = $this->unescapeFieldSegment($field);
        $prettyField = __(Str::headline($field));

        if (
            in_array($rule, ['min', 'minimum', 'max', 'maximum', 'length', 'size']) &&
            $this->isUploadedFile($value)
        ) {
            $this->errors[$field][] = match ($rule) {
                'min', 'minimum' => __($this->getErrorMessagePlaceholder('file_min', $field, 'The %s file must be at least %s KB.'), [$prettyField, $params[0] ?? 0]),
                'max', 'maximum' => __($this->getErrorMessagePlaceholder('file_max', $field, 'The %s file must not exceed %s KB.'), [$prettyField, $params[0] ?? 0]),
                'length', 'size' => __($this->getErrorMessagePlaceholder('file_size', $field, 'The %s file must be %s KB.'), [$prettyField, $params[0] ?? 0]),
            };
            return;
        }

        // Error messages for each validation rule
        $this->errors[$field][] = match ($rule) {
            'required' => __($this->getErrorMessagePlaceholder('required', $field, 'The %s field is required.'), $prettyField),
            'required_if' => __($this->getErrorMessagePlaceholder('required_if', $field, 'The %s field is required when %s is %s.'), [$prettyField, __(Str::headline($params[0] ?? '')), implode(' or ', array_slice($params, 1))]),
            'required_unless' => __($this->getErrorMessagePlaceholder('required_unless', $field, 'The %s field is required unless %s is %s.'), [$prettyField, __(Str::headline($params[0] ?? '')), implode(' or ', array_slice($params, 1))]),
            'email', 'mail' => __($this->getErrorMessagePlaceholder('email', $field, 'The %s field must be a valid email address.'), $prettyField),
            'url', 'link' => __($this->getErrorMessagePlaceholder('url', $field, 'The %s field must be a valid URL.'), $prettyField),
            'number', 'numeric', 'int', 'integer' => __($this->getErrorMessagePlaceholder('number', $field, 'The %s field must be a number.'), $prettyField),
            'array', 'list' => __($this->getErrorMessagePlaceholder('array', $field, 'The %s field must be an array.'), $prettyField),
            'text', 'char', 'string' => __($this->getErrorMessagePlaceholder('text', $field, 'The %s field must be a text.'), $prettyField),
            'min', 'minimum' => __($this->getErrorMessagePlaceholder('min', $field, 'The %s field must be at least %s characters long.'), [$prettyField, $params[0] ?? 0]),
            'max', 'maximum' => __($this->getErrorMessagePlaceholder('max', $field, 'The %s field must not exceed %s characters.'), [$prettyField, $params[0] ?? 0]),
            'length', 'size' => __($this->getErrorMessagePlaceholder('length', $field, 'The %s field must be %s characters.'), [$prettyField, $params[0] ?? 0]),
            'gt' => __($this->getErrorMessagePlaceholder('gt', $field, 'The %s field must be greater than %s.'), [$prettyField, is_scalar($params[0] ?? null) ? (string) $params[0] : 'the comparison field']),
            'gte' => __($this->getErrorMessagePlaceholder('gte', $field, 'The %s field must be greater than or equal to %s.'), [$prettyField, is_scalar($params[0] ?? null) ? (string) $params[0] : 'the comparison field']),
            'lt', 'ls' => __($this->getErrorMessagePlaceholder($rule, $field, 'The %s field must be less than %s.'), [$prettyField, is_scalar($params[0] ?? null) ? (string) $params[0] : 'the comparison field']),
            'lte' => __($this->getErrorMessagePlaceholder('lte', $field, 'The %s field must be less than or equal to %s.'), [$prettyField, is_scalar($params[0] ?? null) ? (string) $params[0] : 'the comparison field']),
            'equal', 'same', 'same_as' => __($this->getErrorMessagePlaceholder('equal', $field, 'The %s field must be equal to %s field.'), [$prettyField, __(Str::headline($params[0] ?? ''))]),
            'confirmed' => __($this->getErrorMessagePlaceholder('confirmed', $field, 'The %s field must be confirmed.'), $prettyField),
            'in' => __($this->getErrorMessagePlaceholder('in', $field, 'The %s field must be one of the following values: %s.'), [$prettyField, implode(', ', $params)]),
            'not_in' => __($this->getErrorMessagePlaceholder('not_in', $field, 'The %s field must not be one of the following values: %s.'), [$prettyField, implode(', ', $params)]),
            'regex' => __($this->getErrorMessagePlaceholder('regex', $field, 'The %s field must match the pattern: %s.'), [$prettyField, $params[0] ?? '']),
            'unique' => __($this->getErrorMessagePlaceholder('unique', $field, 'The %s field must be unique in the %s table.'), [$prettyField, $params[0] ?? '']),
            'exists' => __($this->getErrorMessagePlaceholder('exists', $field, 'The %s field must exist in the %s table.'), [$prettyField, $params[0] ?? '']),
            'not_exists' => __($this->getErrorMessagePlaceholder('not_exists', $field, 'The %s field must not exist in the %s table.'), [$prettyField, $params[0] ?? '']),
            'boolean', 'bool' => __($this->getErrorMessagePlaceholder('boolean', $field, 'The %s field must be true or false.'), $prettyField),
            'float', 'decimal' => __($this->getErrorMessagePlaceholder('float', $field, 'The %s field must be a decimal number.'), $prettyField),
            'alpha' => __($this->getErrorMessagePlaceholder('alpha', $field, in_array('ascii', $params, true) ? 'The %s field must contain only ASCII letters.' : 'The %s field must contain only letters.'), $prettyField),
            'alpha_num', 'alphanumeric' => __($this->getErrorMessagePlaceholder('alpha_num', $field, in_array('ascii', $params, true) ? 'The %s field must contain only ASCII letters and numbers.' : 'The %s field must contain only letters and numbers.'), $prettyField),
            'alpha_dash' => __($this->getErrorMessagePlaceholder('alpha_dash', $field, in_array('ascii', $params, true) ? 'The %s field must contain only ASCII letters, numbers, dashes, and underscores.' : 'The %s field must contain only letters, numbers, dashes, and underscores.'), $prettyField),
            'ascii' => __($this->getErrorMessagePlaceholder('ascii', $field, 'The %s field must contain only ASCII characters.'), $prettyField),
            'digits' => __($this->getErrorMessagePlaceholder('digits', $field, 'The %s field must be %s digits.'), [$prettyField, $params[0] ?? 0]),
            'digits_between' => __($this->getErrorMessagePlaceholder('digits_between', $field, 'The %s field must be between %s and %s digits.'), [$prettyField, $params[0] ?? 0, $params[1] ?? 0]),
            'min_digits' => __($this->getErrorMessagePlaceholder('min_digits', $field, 'The %s field must be at least %s digits.'), [$prettyField, $params[0] ?? 0]),
            'max_digits' => __($this->getErrorMessagePlaceholder('max_digits', $field, 'The %s field must not exceed %s digits.'), [$prettyField, $params[0] ?? 0]),
            'date' => __($this->getErrorMessagePlaceholder('date', $field, 'The %s field must be a valid date.'), $prettyField),
            'date_format' => __($this->getErrorMessagePlaceholder('date_format', $field, 'The %s field must match the format %s.'), [$prettyField, $params[0] ?? 'Y-m-d']),
            'before' => __($this->getErrorMessagePlaceholder('before', $field, 'The %s field must be before %s.'), [$prettyField, $params[0] ?? 'now']),
            'after' => __($this->getErrorMessagePlaceholder('after', $field, 'The %s field must be after %s.'), [$prettyField, $params[0] ?? 'now']),
            'between' => __($this->getErrorMessagePlaceholder('between', $field, 'The %s field must be between %s and %s.'), [$prettyField, $params[0] ?? 0, $params[1] ?? 0]),
            'json' => __($this->getErrorMessagePlaceholder('json', $field, 'The %s field must be valid JSON.'), $prettyField),
            'ip' => __($this->getErrorMessagePlaceholder('ip', $field, 'The %s field must be a valid IP address.'), $prettyField),
            'ipv4' => __($this->getErrorMessagePlaceholder('ipv4', $field, 'The %s field must be a valid IPv4 address.'), $prettyField),
            'ipv6' => __($this->getErrorMessagePlaceholder('ipv6', $field, 'The %s field must be a valid IPv6 address.'), $prettyField),
            'mac_address' => __($this->getErrorMessagePlaceholder('mac_address', $field, 'The %s field must be a valid MAC address.'), $prettyField),
            'uuid' => __($this->getErrorMessagePlaceholder('uuid', $field, 'The %s field must be a valid UUID.'), $prettyField),
            'lowercase' => __($this->getErrorMessagePlaceholder('lowercase', $field, 'The %s field must be lowercase.'), $prettyField),
            'uppercase' => __($this->getErrorMessagePlaceholder('uppercase', $field, 'The %s field must be uppercase.'), $prettyField),
            'starts_with' => __($this->getErrorMessagePlaceholder('starts_with', $field, 'The %s field must start with %s.'), [$prettyField, $params[0] ?? '']),
            'ends_with' => __($this->getErrorMessagePlaceholder('ends_with', $field, 'The %s field must end with %s.'), [$prettyField, $params[0] ?? '']),
            'contains' => __($this->getErrorMessagePlaceholder('contains', $field, 'The %s field must contain %s.'), [$prettyField, $params[0] ?? '']),
            'not_contains' => __($this->getErrorMessagePlaceholder('not_contains', $field, 'The %s field must not contain %s.'), [$prettyField, $params[0] ?? '']),
            'present' => __($this->getErrorMessagePlaceholder('present', $field, 'The %s field must be present.'), $prettyField),
            'filled' => __($this->getErrorMessagePlaceholder('filled', $field, 'The %s field must be filled when present.'), $prettyField),
            'accepted' => __($this->getErrorMessagePlaceholder('accepted', $field, 'The %s field must be accepted.'), $prettyField),
            'declined' => __($this->getErrorMessagePlaceholder('declined', $field, 'The %s field must be declined.'), $prettyField),
            'prohibited' => __($this->getErrorMessagePlaceholder('prohibited', $field, 'The %s field is prohibited.'), $prettyField),
            'file' => __($this->getErrorMessagePlaceholder('file', $field, 'The %s field must be a file.'), $prettyField),
            'image' => __($this->getErrorMessagePlaceholder('image', $field, 'The %s field must be an image.'), $prettyField),
            'mimes' => __($this->getErrorMessagePlaceholder('mimes', $field, 'The %s field must be a file of type: %s.'), [$prettyField, implode(', ', $params)]),
            'min_value' => __($this->getErrorMessagePlaceholder('min_value', $field, 'The %s field must be at least %s.'), [$prettyField, $params[0] ?? 0]),
            'max_value' => __($this->getErrorMessagePlaceholder('max_value', $field, 'The %s field must not be greater than %s.'), [$prettyField, $params[0] ?? 0]),
            'distinct' => __($this->getErrorMessagePlaceholder('distinct', $field, 'The %s field has duplicate values.'), $prettyField),
            'password' => __($this->getErrorMessagePlaceholder('password', $field, 'The %s field must meet password requirements.'), $prettyField),
            // Default case for unrecognized rules
            default => __($this->getErrorMessagePlaceholder('default', $field, 'The %s field has an invalid value.'), $prettyField)
        };
    }

    /**
     * Add a custom error message for a field (used by RuleContract and Closures).
     *
     * @param string $field
     * @param string $message
     * @return void
     */
    public function addCustomError(string $field, string $message): void
    {
        $field = $this->unescapeFieldSegment($field);
        $prettyField = __(Str::headline($field));
        $formattedMessage = str_replace([':attribute', '%s'], $prettyField, $message);

        $this->errors[$field][] = $formattedMessage;
    }

    /**
     * Returns the error message placeholder for a specific rule.
     *
     * @param string $rule The validation rule name.
     * @param string $field The field name being validated.
     * @param string $default The default error message if no custom message is set.
     * @return string The error message for the rule.
     */
    protected function getErrorMessagePlaceholder(string $rule, string $field, string $default): string
    {
        $field = strtolower($field);
        $field2 = strtolower("$field.$rule");

        if (isset(self::$errorMessages[$field2])) {
            return self::$errorMessages[$field2];
        }

        $placeholder = self::$errorMessages[$rule] ?? null;
        if (is_array($placeholder)) {
            return $placeholder[$field] ?? $placeholder['default'] ?? $default;
        }

        return $placeholder ?? $default;
    }
}
