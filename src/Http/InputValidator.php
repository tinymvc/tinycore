<?php

namespace Spark\Http;

use Spark\Contracts\Http\InputValidatorContract;
use Spark\Support\Str;
use Spark\Support\Traits\Macroable;

/**
 * Class Validator
 * 
 * Validator class provides methods to validate data based on specified rules.
 * Includes validation methods for common data types and constraints.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class InputValidator implements InputValidatorContract
{
    use Macroable;

    /**
     * @var array $errorMessages Custom error messages for validation rules.
     * 
     * This static property holds custom error messages that can be set
     * for various validation rules. It allows customization of error messages
     * returned during validation failures.
     */
    private static array $errorMessages = [];

    /**
     * Constructs a new validator instance.
     * 
     * @param array $errors Optional array of errors to start with.
     */
    public function __construct(private array $errors = [])
    {
    }

    /**
     * Validates input data against specified rules.
     *
     * @param string|array $rules Array of validation rules where the key is the field name
     *                     and the value is an array of rules for that field.
     * @param array $inputData Array of input data to validate.
     * @return bool|InputSanitizer Returns validated data as an InputSanitizer instance if valid,
     *                             or false if validation fails.
     */
    public function validate(string|array $rules, array $inputData): bool|InputSanitizer
    {
        $validData = [];
        foreach ($rules as $field => $fieldRules) {
            $value = $inputData[$field] ?? null;
            $valid = true;

            if (is_string($fieldRules)) {
                $fieldRules = array_map('trim', explode('|', $fieldRules));
            }

            // Check if field is required
            $is_required = in_array('required', $fieldRules, true);

            // Loop through field rules
            foreach ($fieldRules as $rule) {
                // Parse rule name and parameters
                $ruleName = $rule;
                $ruleParams = [];
                if (strpos($rule, ':') !== false) {
                    [$ruleName, $ruleParams] = array_map('trim', explode(':', $rule, 2));
                    $ruleParams = array_map('trim', explode(',', $ruleParams));
                }

                // Check if value is valid
                $has_valid_value = $value === null ? false : (is_array($value) ? !empty($value) : '' !== $value);

                // Apply validation rule
                $valid = match ($ruleName) {
                    'required' => $has_valid_value,
                    'email', 'mail' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_EMAIL) : true,
                    'url', 'link' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_URL) : true,
                    'number', 'int', 'integer' => ($has_valid_value || $is_required) ? is_numeric($value) : true,
                    'array', 'list' => ($has_valid_value || $is_required) ? is_array($value) : true,
                    'text', 'char', 'string' => ($has_valid_value || $is_required) ? is_string($value) : true,
                    'min', 'minimum' => ($has_valid_value || $is_required) ? strlen($value ?? '') >= (int) $ruleParams[0] : true,
                    'max', 'maximum' => ($has_valid_value || $is_required) ? strlen($value ?? '') <= (int) $ruleParams[0] : true,
                    'length', 'size' => ($has_valid_value || $is_required) ? strlen($value ?? '') == (int) $ruleParams[0] : true,
                    'equal', 'same', 'same_as' => ($has_valid_value || $is_required) ? $value == ($inputData[$ruleParams[0]] ?? null) : true,
                    'confirmed' => ($has_valid_value || $is_required) ? $value == ($inputData["{$field}_confirmation"] ?? null) : true,
                    'in', 'exists' => ($has_valid_value || $is_required) ? in_array($value, $ruleParams, true) : true,
                    'not_in', 'not_exists' => ($has_valid_value || $is_required) ? !in_array($value, $ruleParams, true) : true,
                    'regex' => ($has_valid_value || $is_required) ? preg_match($ruleParams[0], $value) : true,
                    'unique' => ($has_valid_value || $is_required) ? query($ruleParams[0])->where($ruleParams[1] ?? $field, $value)->where(isset($ruleParams[2]) ? ("id != " . intval($ruleParams[2])) : null)->count() === 0 : true,
                    'boolean', 'bool' => ($has_valid_value || $is_required) ? in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false', 'on', 'off', 'yes', 'no'], true) : true,
                    'float', 'decimal' => ($has_valid_value || $is_required) ? is_numeric($value) && (float) $value == $value : true,
                    'alpha' => ($has_valid_value || $is_required) ? ctype_alpha($value) : true,
                    'alpha_num', 'alphanumeric' => ($has_valid_value || $is_required) ? ctype_alnum($value) : true,
                    'alpha_dash' => ($has_valid_value || $is_required) ? preg_match('/^[a-zA-Z0-9_-]+$/', $value) : true,
                    'numeric' => ($has_valid_value || $is_required) ? is_numeric($value) : true,
                    'digits' => ($has_valid_value || $is_required) ? ctype_digit($value) && strlen($value) == (int) $ruleParams[0] : true,
                    'digits_between' => ($has_valid_value || $is_required) ? ctype_digit($value) && strlen($value) >= (int) $ruleParams[0] && strlen($value) <= (int) $ruleParams[1] : true,
                    'min_digits' => ($has_valid_value || $is_required) ? ctype_digit($value) && strlen($value) >= (int) $ruleParams[0] : true,
                    'max_digits' => ($has_valid_value || $is_required) ? ctype_digit($value) && strlen($value) <= (int) $ruleParams[0] : true,
                    'date' => ($has_valid_value || $is_required) ? strtotime($value) !== false : true,
                    'date_format' => ($has_valid_value || $is_required) ? $this->validateDateFormat($value, $ruleParams[0] ?? 'Y-m-d') : true,
                    'before' => ($has_valid_value || $is_required) ? strtotime($value) < strtotime($ruleParams[0] ?? 'now') : true,
                    'after' => ($has_valid_value || $is_required) ? strtotime($value) > strtotime($ruleParams[0] ?? 'now') : true,
                    'between' => ($has_valid_value || $is_required) ? $this->validateBetween($value, $ruleParams) : true,
                    'json' => ($has_valid_value || $is_required) ? json_decode($value) !== null : true,
                    'ip' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_IP) : true,
                    'ipv4' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) : true,
                    'ipv6' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) : true,
                    'mac_address' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_MAC) : true,
                    'uuid' => ($has_valid_value || $is_required) ? preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) : true,
                    'lowercase' => ($has_valid_value || $is_required) ? $value === strtolower($value) : true,
                    'uppercase' => ($has_valid_value || $is_required) ? $value === strtoupper($value) : true,
                    'starts_with' => ($has_valid_value || $is_required) ? str_starts_with($value, $ruleParams[0] ?? '') : true,
                    'ends_with' => ($has_valid_value || $is_required) ? str_ends_with($value, $ruleParams[0] ?? '') : true,
                    'contains' => ($has_valid_value || $is_required) ? str_contains($value, $ruleParams[0] ?? '') : true,
                    'not_contains' => ($has_valid_value || $is_required) ? !str_contains($value, $ruleParams[0] ?? '') : true,
                    'nullable' => true, // Always passes, allows null values
                    'present' => array_key_exists($field, $inputData), // Field must be present but can be empty
                    'filled' => $has_valid_value, // Field must be present and not empty if present
                    'accepted' => in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true),
                    'declined' => in_array($value, [false, 0, '0', 'false', 'off', 'no'], true),
                    'prohibited' => !$has_valid_value, // Field must be empty or not present
                    'file' => ($has_valid_value || $is_required) ? is_uploaded_file($value['tmp_name'] ?? '') : true,
                    'image' => ($has_valid_value || $is_required) ? $this->validateImage($value) : true,
                    'mimes' => ($has_valid_value || $is_required) ? $this->validateMimes($value, $ruleParams) : true,
                    'min_value' => ($has_valid_value || $is_required) ? is_numeric($value) && (float) $value >= (float) $ruleParams[0] : true,
                    'max_value' => ($has_valid_value || $is_required) ? is_numeric($value) && (float) $value <= (float) $ruleParams[0] : true,
                    'distinct' => $this->validateDistinct($value),
                    'password' => ($has_valid_value || $is_required) ? $this->validatePassword($value, $ruleParams) : true,

                    default => true // Default to true if rule is not recognized
                };

                // Add error if rule validation fails
                if (!$valid) {
                    $this->addError($field, $ruleName, $ruleParams);
                }
            }

            // Store valid data if field passed all rules
            if ($valid) {
                $validData[$field] = $value;
            }
        }

        // Return validated data or false if there are errors
        return empty($this->errors) ? new InputSanitizer($validData) : false;
    }

    /**
     * Validate date format
     * 
     * Validates if a date string matches a specified format.
     * 
     * @param string $value The date value to validate.
     * @param string $format The expected date format (e.g., 'Y-m-d').
     * @return bool True if the date matches the format, false otherwise.
     */
    private function validateDateFormat(string $value, string $format): bool
    {
        $date = \DateTime::createFromFormat($format, $value);
        return $date && $date->format($format) === $value;
    }

    /**
     * Validate between rule for numeric and string values
     * 
     * Validates if a numeric or string value is between two specified limits.
     * 
     * @param mixed $value The value to validate (numeric or string).
     * @param array $params Array containing two elements: minimum and maximum limits.
     * @return bool True if the value is between the limits, false otherwise.
     */
    private function validateBetween($value, array $params): bool
    {
        if (count($params) < 2)
            return false;

        $min = $params[0];
        $max = $params[1];

        if (is_numeric($value)) {
            return (float) $value >= (float) $min && (float) $value <= (float) $max;
        }

        $length = strlen($value);
        return $length >= (int) $min && $length <= (int) $max;
    }

    /**
     * Validate image file
     * 
     * Validates if a file is a valid image by checking its MIME type.
     * 
     * @param array $file The uploaded file array containing 'tmp_name'.
     * @return bool True if the file is a valid image, false otherwise.
     */
    private function validateImage($file): bool
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        $imageInfo = getimagesize($file['tmp_name']);
        return $imageInfo !== false;
    }

    /**
     * Validate file MIME types
     * 
     * Validates if a file's MIME type is in the allowed list.
     * 
     * @param array $file The uploaded file array containing 'tmp_name'.
     * @param array $allowedMimes Array of allowed MIME types.
     * @return bool True if the file's MIME type is allowed, false otherwise.
     */
    private function validateMimes($file, array $allowedMimes): bool
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        return in_array($mimeType, $allowedMimes, true);
    }

    /**
     * Validate distinct values in array
     * 
     * Validates that all values in an array are distinct.
     * 
     * @param mixed $value The value to validate (should be an array).
     * @return bool True if all values are distinct, false otherwise.
     */
    private function validateDistinct($value): bool
    {
        // If the field itself is an array, check for unique values within it
        if (is_array($value)) {
            return count($value) === count(array_unique($value, SORT_REGULAR));
        }

        // For non-array fields, we don't need to validate distinctness
        // This rule is primarily for array fields
        return true;
    }

    /**
     * Validate password strength
     * 
     * Validates if a password meets specified strength requirements.
     * 
     * @param string $value The password value to validate.
     * @param array $params Array of parameters for password validation:
     *                      - Minimum length (default 8)
     *                     - 'uppercase' to require at least one uppercase letter
     *                     - 'lowercase' to require at least one lowercase letter
     *                     - 'numbers' to require at least one digit
     *                     - 'symbols' to require at least one special character
     * @return bool True if the password meets all requirements, false otherwise.
     */
    private function validatePassword(string $value, array $params): bool
    {
        $minLength = (int) ($params[0] ?? 8);
        $requireUppercase = in_array('uppercase', $params, true);
        $requireLowercase = in_array('lowercase', $params, true);
        $requireNumbers = in_array('numbers', $params, true);
        $requireSymbols = in_array('symbols', $params, true);

        if (strlen($value) < $minLength)
            return false;
        if ($requireUppercase && !preg_match('/[A-Z]/', $value))
            return false;
        if ($requireLowercase && !preg_match('/[a-z]/', $value))
            return false;
        if ($requireNumbers && !preg_match('/\d/', $value))
            return false;
        if ($requireSymbols && !preg_match('/[^a-zA-Z\d]/', $value))
            return false;

        return true;
    }

    /**
     * Returns all validation errors.
     *
     * @return array Associative array of field names and error messages.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns the first error message, if any.
     *
     * @return string|null First error message or null if no errors.
     */
    public function getFirstError(): ?string
    {
        return !empty($this->errors) ? (array_values(array_values($this->errors)[0] ?? [])[0] ?? null) : null;
    }

    /**
     * Sets custom error messages for validation rules.
     *
     * @param array $messages Associative array of rule names and their custom error messages.
     */
    public static function setErrorMessages(array $messages): void
    {
        self::$errorMessages = $messages;
    }

    /**
     * Sets a custom error message for a specific validation rule.
     *
     * @param string $rule Validation rule name.
     * @param array|string $message Custom error message for the rule.
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
     */
    private function addError(string $field, string $rule, array $params = []): void
    {
        $prettyField = __(Str::headline($field));

        // Error messages for each validation rule
        $this->errors[$field][] = match ($rule) {
            'required' => __($this->getErrorMessagePlaceholder('required', $field, 'The %s field is required.'), $prettyField),
            'email', 'mail' => __($this->getErrorMessagePlaceholder('email', $field, 'The %s field must be a valid email address.'), $prettyField),
            'url', 'link' => __($this->getErrorMessagePlaceholder('url', $field, 'The %s field must be a valid URL.'), $prettyField),
            'number', 'int', 'integer' => __($this->getErrorMessagePlaceholder('number', $field, 'The %s field must be a number.'), $prettyField),
            'array', 'list' => __($this->getErrorMessagePlaceholder('array', $field, 'The %s field must be an array.'), $prettyField),
            'text', 'char', 'string' => __($this->getErrorMessagePlaceholder('text', $field, 'The %s field must be a text.'), $prettyField),
            'min', 'minimum' => __($this->getErrorMessagePlaceholder('min', $field, 'The %s field must be at least %s characters long.'), [$prettyField, $params[0] ?? 0]),
            'max', 'maximum' => __($this->getErrorMessagePlaceholder('max', $field, 'The %s field must not exceed %s characters.'), [$prettyField, $params[0] ?? 0]),
            'length', 'size' => __($this->getErrorMessagePlaceholder('length', $field, 'The %s field must be %s characters.'), [$prettyField, $params[0] ?? 0]),
            'equal', 'same', 'same_as' => __($this->getErrorMessagePlaceholder('equal', $field, 'The %s field must be equal to %s field.'), [$prettyField, __(Str::headline($params[0] ?? ''))]),
            'confirmed' => __($this->getErrorMessagePlaceholder('confirmed', $field, 'The %s field must be confirmed.'), $prettyField),
            'in', 'exists' => __($this->getErrorMessagePlaceholder('in', $field, 'The %s field must be one of the following values: %s.'), [$prettyField, implode(', ', $params)]),
            'not_in', 'not_exists' => __($this->getErrorMessagePlaceholder('not_in', $field, 'The %s field must not be one of the following values: %s.'), [$prettyField, implode(', ', $params)]),
            'regex' => __($this->getErrorMessagePlaceholder('regex', $field, 'The %s field must match the pattern: %s.'), [$prettyField, $params[0] ?? '']),
            'unique' => __($this->getErrorMessagePlaceholder('unique', $field, 'The %s field must be unique in the %s table.'), [$prettyField, $params[0] ?? '']),
            'boolean', 'bool' => __($this->getErrorMessagePlaceholder('boolean', $field, 'The %s field must be true or false.'), $prettyField),
            'float', 'decimal' => __($this->getErrorMessagePlaceholder('float', $field, 'The %s field must be a decimal number.'), $prettyField),
            'alpha' => __($this->getErrorMessagePlaceholder('alpha', $field, 'The %s field must contain only letters.'), $prettyField),
            'alpha_num', 'alphanumeric' => __($this->getErrorMessagePlaceholder('alpha_num', $field, 'The %s field must contain only letters and numbers.'), $prettyField),
            'alpha_dash' => __($this->getErrorMessagePlaceholder('alpha_dash', $field, 'The %s field must contain only letters, numbers, dashes, and underscores.'), $prettyField),
            'numeric' => __($this->getErrorMessagePlaceholder('numeric', $field, 'The %s field must be numeric.'), $prettyField),
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
     * Returns the error message placeholder for a specific rule.
     *
     * @param string $rule The validation rule name.
     * @param string $field The field name being validated.
     * @param string $default The default error message if no custom message is set.
     * @return string The error message for the rule.
     */
    private function getErrorMessagePlaceholder(string $rule, string $field, string $default): string
    {
        $field = strtolower($field); // make the field name in lowercase

        $placeholder = self::$errorMessages[$rule] ?? null;
        if (is_array($placeholder)) {
            return $placeholder[$field] ?? $placeholder['default'] ?? $default;
        }

        return $placeholder ?? $default;
    }
}