<?php

namespace Spark\Http;

use Spark\Contracts\Http\ValidatorContract;
use Spark\Support\Str;
use Spark\Support\Traits\Macroable;
use function array_key_exists;
use function array_slice;
use function count;
use function in_array;
use function intval;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function strlen;

/**
 * Class Validator
 * 
 * Validator class provides methods to validate data based on specified rules.
 * Includes validation methods for common data types and constraints.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Validator implements ValidatorContract
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

    /** @var Sanitizer */
    private Sanitizer $cleanData;

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
     * @return bool|Sanitizer Returns validated data as an Sanitizer instance if valid,
     *                             or false if validation fails.
     */
    public function validate(string|array $rules, array $inputData): bool|Sanitizer
    {
        $validData = [];
        foreach ($rules as $field => $fieldRules) {
            $value = $inputData[$field] ?? null;
            $valid = true;

            if (is_string($fieldRules)) {
                $fieldRules = array_map('trim', explode('|', $fieldRules));
            }

            // Check if field is not nullable
            $is_not_nullable = !in_array('nullable', $fieldRules, true);

            // Check if field has numeric validation rules
            $is_numeric_field = $this->hasNumericValidation($fieldRules);

            // Loop through field rules
            foreach ($fieldRules as $rule) {
                // Parse rule name and parameters
                $ruleName = $rule;
                $ruleParams = [];
                if (str_contains($rule, ':')) {
                    [$ruleName, $ruleParams] = array_map('trim', explode(':', $rule, 2));
                    $ruleParams = array_map('trim', explode(',', $ruleParams));
                }

                // Check if value is valid
                $has_valid_value = $this->hasValidValue($value, $is_numeric_field);

                // Apply validation rule
                $valid = match ($ruleName) {
                    'required' => $has_valid_value,
                    'required_if' => $this->validateRequiredIf($ruleParams, $inputData, $has_valid_value),
                    'required_unless' => $this->validateRequiredUnless($ruleParams, $inputData, $has_valid_value),
                    'email', 'mail' => ($has_valid_value || $is_not_nullable) ? filter_var($value, FILTER_VALIDATE_EMAIL) !== false : true,
                    'url', 'link' => ($has_valid_value || $is_not_nullable) ? filter_var($value, FILTER_VALIDATE_URL) !== false : true,
                    'number', 'numeric', 'int', 'integer' => ($has_valid_value || $is_not_nullable) ? is_numeric($value) : true,
                    'array', 'list' => ($has_valid_value || $is_not_nullable) ? is_array($value) : true,
                    'text', 'char', 'string' => ($has_valid_value || $is_not_nullable) ? is_string($value) : true,
                    'min', 'minimum' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? $this->compareMin($value, $ruleParams[0], $is_numeric_field) : true,
                    'max', 'maximum' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? $this->compareMax($value, $ruleParams[0], $is_numeric_field) : true,
                    'length', 'size' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? $this->compareSize($value, $ruleParams[0], $is_numeric_field) : true,
                    'equal', 'same', 'same_as' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? $this->validateEqual($value, $inputData[$ruleParams[0]] ?? null) : true,
                    'confirmed' => ($has_valid_value || $is_not_nullable) ? $value == ($inputData["{$field}_confirmation"] ?? null) : true,
                    'in' => ($has_valid_value || $is_not_nullable) ? $this->validateIn($value, $ruleParams) : true,
                    'not_in' => ($has_valid_value || $is_not_nullable) ? !$this->validateIn($value, $ruleParams) : true,
                    'regex' => ($has_valid_value || $is_not_nullable) ? $this->validateRegex($value, $ruleParams) : true,
                    'unique' => ($has_valid_value || $is_not_nullable) ? $this->validateUnique($value, $ruleParams, $field) : true,
                    'exists' => ($has_valid_value || $is_not_nullable) ? $this->validateExists($value, $ruleParams, $field) : true,
                    'not_exists' => ($has_valid_value || $is_not_nullable) ? $this->validateNotExists($value, $ruleParams, $field) : true,
                    'boolean', 'bool' => ($has_valid_value || $is_not_nullable) ? in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false', 'TRUE', 'FALSE', 'on', 'off', 'yes', 'no', 'YES', 'NO'], true) : true,
                    'float', 'decimal' => ($has_valid_value || $is_not_nullable) ? (is_numeric($value) && (str_contains((string) $value, '.') || str_contains((string) $value, 'e') || str_contains((string) $value, 'E'))) : true,
                    'alpha' => ($has_valid_value || $is_not_nullable) ? preg_match('/^[\pL]+$/u', $value) : true,
                    'alpha_num', 'alphanumeric' => ($has_valid_value || $is_not_nullable) ? preg_match('/^[\pL\pN]+$/u', $value) : true,
                    'alpha_dash' => ($has_valid_value || $is_not_nullable) ? preg_match('/^[a-zA-Z0-9_-]+$/', $value) : true,
                    'digits' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? ctype_digit((string) $value) && strlen((string) $value) == (int) $ruleParams[0] : true,
                    'digits_between' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0], $ruleParams[1]) ? ctype_digit((string) $value) && strlen((string) $value) >= (int) $ruleParams[0] && strlen((string) $value) <= (int) $ruleParams[1] : true,
                    'min_digits' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? ctype_digit((string) $value) && strlen((string) $value) >= (int) $ruleParams[0] : true,
                    'max_digits' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? ctype_digit((string) $value) && strlen((string) $value) <= (int) $ruleParams[0] : true,
                    'date' => ($has_valid_value || $is_not_nullable) ? $this->validateDate($value) : true,
                    'date_format' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? $this->validateDateFormat($value, $ruleParams[0]) : true,
                    'before' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? $this->validateBefore($value, $ruleParams[0]) : true,
                    'after' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? $this->validateAfter($value, $ruleParams[0]) : true,
                    'between' => ($has_valid_value || $is_not_nullable) ? $this->validateBetween($value, $ruleParams, $is_numeric_field) : true,
                    'json' => ($has_valid_value || $is_not_nullable) ? json_decode($value) !== null && json_last_error() === JSON_ERROR_NONE : true,
                    'ip' => ($has_valid_value || $is_not_nullable) ? filter_var($value, FILTER_VALIDATE_IP) !== false : true,
                    'ipv4' => ($has_valid_value || $is_not_nullable) ? filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false : true,
                    'ipv6' => ($has_valid_value || $is_not_nullable) ? filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false : true,
                    'mac_address' => ($has_valid_value || $is_not_nullable) ? filter_var($value, FILTER_VALIDATE_MAC) !== false : true,
                    'uuid' => ($has_valid_value || $is_not_nullable) ? preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) : true,
                    'lowercase' => ($has_valid_value || $is_not_nullable) ? (is_string($value) && $value === strtolower($value)) : true,
                    'uppercase' => ($has_valid_value || $is_not_nullable) ? (is_string($value) && $value === strtoupper($value)) : true,
                    'starts_with' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? (isset($ruleParams[0]) && $ruleParams[0] !== '' && str_starts_with((string) $value, $ruleParams[0])) : true,
                    'ends_with' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? (isset($ruleParams[0]) && $ruleParams[0] !== '' && str_ends_with((string) $value, $ruleParams[0])) : true,
                    'contains' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? (isset($ruleParams[0]) && $ruleParams[0] !== '' && str_contains((string) $value, $ruleParams[0])) : true,
                    'not_contains' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? (isset($ruleParams[0]) && $ruleParams[0] !== '' && !str_contains((string) $value, $ruleParams[0])) : true,
                    'nullable' => true, // Always passes, allows null values
                    'present' => array_key_exists($field, $inputData), // Field must be present but can be empty
                    'filled' => $has_valid_value, // Field must be present and not empty if present
                    'accepted' => in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true) || in_array($value, [true, 1], true),
                    'declined' => in_array(strtolower((string) $value), ['0', 'false', 'off', 'no'], true) || in_array($value, [false, 0], true),
                    'prohibited' => !$has_valid_value, // Field must be empty or not present
                    'file' => ($has_valid_value || $is_not_nullable) ? (is_array($value) && isset($value['tmp_name']) && is_uploaded_file($value['tmp_name'])) : true,
                    'image' => ($has_valid_value || $is_not_nullable) ? $this->validateImage($value) : true,
                    'mimes' => ($has_valid_value || $is_not_nullable) ? $this->validateMimes($value, $ruleParams) : true,
                    'min_value' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? is_numeric($value) && (float) $value >= (float) $ruleParams[0] : true,
                    'max_value' => ($has_valid_value || $is_not_nullable) && isset($ruleParams[0]) ? is_numeric($value) && (float) $value <= (float) $ruleParams[0] : true,
                    'distinct' => $this->validateDistinct($value),
                    'password' => ($has_valid_value || $is_not_nullable) ? $this->validatePassword($value, $ruleParams) : true,
                    default => true // Default to true if rule is not recognized
                };

                // Add error if rule validation fails
                if (!$valid) {
                    $this->addError($field, $ruleName, $ruleParams, $value);
                }
            }

            // Store valid data if field passed all rules
            if ($valid) {
                $validData[$field] = $value;
            }
        }

        // Return validated data or false if there are errors
        return empty($this->errors) ? $this->cleanData = new Sanitizer($validData) : false;
    }

    /**
     * Validate required_if rule
     * 
     * The field is required if another field equals a specific value.
     * 
     * @param array $params Array containing [field_name, expected_value, ...]
     * @param array $inputData All input data
     * @param bool $hasValidValue Whether the field has a valid value
     * @return bool True if validation passes
     */
    private function validateRequiredIf(array $params, array $inputData, bool $hasValidValue): bool
    {
        if (count($params) < 2) {
            return false; // Need at least field name and value
        }

        $otherField = $params[0];
        $expectedValues = array_slice($params, 1); // Support multiple values
        $otherValue = $inputData[$otherField] ?? null;

        // Check if other field matches any of the expected values
        $shouldBeRequired = false;
        foreach ($expectedValues as $expectedValue) {
            if ($this->validateEqual($otherValue, $expectedValue)) {
                $shouldBeRequired = true;
                break;
            }
        }

        // If field should be required, check if it has valid value
        if ($shouldBeRequired) {
            return $hasValidValue;
        }

        // If field is not required, it's always valid
        return true;
    }

    /**
     * Validate required_unless rule
     * 
     * The field is required unless another field equals a specific value.
     * 
     * @param array $params Array containing [field_name, expected_value, ...]
     * @param array $inputData All input data
     * @param bool $hasValidValue Whether the field has a valid value
     * @return bool True if validation passes
     */
    private function validateRequiredUnless(array $params, array $inputData, bool $hasValidValue): bool
    {
        if (count($params) < 2) {
            return false; // Need at least field name and value
        }

        $otherField = $params[0];
        $expectedValues = array_slice($params, 1); // Support multiple values
        $otherValue = $inputData[$otherField] ?? null;

        // Check if other field matches any of the expected values
        $shouldNotBeRequired = false;
        foreach ($expectedValues as $expectedValue) {
            if ($this->validateEqual($otherValue, $expectedValue)) {
                $shouldNotBeRequired = true;
                break;
            }
        }

        // If field should not be required, it's always valid
        if ($shouldNotBeRequired) {
            return true;
        }

        // If field should be required, check if it has valid value
        return $hasValidValue;
    }

    /**
     * Check if a value is considered "valid" (not empty)
     */
    private function hasValidValue($value, bool $is_numeric_field = false): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return !empty($value);
        }

        if ($is_numeric_field) {
            return is_numeric($value);
        }

        if (is_string($value)) {
            return $value !== '';
        }

        if (is_bool($value)) {
            return true; // Both true and false are valid values
        }

        return true; // For other types, consider them valid if not null
    }

    /**
     * Check if field has numeric validation rules
     * 
     * @param array $rules Array of validation rules for a field
     * @return bool True if field has numeric validation rules
     */
    private function hasNumericValidation(array $rules): bool
    {
        $numericRules = ['number', 'numeric', 'int', 'integer', 'float', 'decimal'];

        foreach ($rules as $rule) {
            $ruleName = explode(':', $rule)[0];
            if (in_array($ruleName, $numericRules, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate 'in' rule with better type handling
     */
    private function validateIn($value, array $allowedValues): bool
    {
        // Direct comparison first
        if (in_array($value, $allowedValues, true)) {
            return true;
        }

        // Loose comparison for string/numeric values
        if (is_scalar($value)) {
            return in_array($value, $allowedValues, false);
        }

        return false;
    }

    /**
     * Validate equal rule with better type handling
     */
    private function validateEqual($value1, $value2): bool
    {
        // Strict comparison first
        if ($value1 === $value2) {
            return true;
        }

        // Loose comparison for scalar values
        if (is_scalar($value1) && is_scalar($value2)) {
            return $value1 == $value2;
        }

        return false;
    }

    /**
     * Validate regex rule with pattern validation
     * 
     * @param mixed $value The value to validate against regex
     * @param array $params Array containing the regex pattern
     * @return bool True if value matches pattern and pattern is valid
     */
    private function validateRegex($value, array $params): bool
    {
        if (empty($params[0])) {
            return false; // No pattern provided
        }

        $pattern = $params[0];

        // Validate that pattern is a valid regex
        if (@preg_match($pattern, '') === false) {
            trigger_error("Invalid regex pattern provided: $pattern", E_USER_WARNING);
            return false;
        }

        // Protect against ReDoS by setting a timeout
        $originalTimeout = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000'); // Limit backtracking

        try {
            $result = @preg_match($pattern, (string) $value);
            return $result === 1;
        } finally {
            ini_set('pcre.backtrack_limit', $originalTimeout);
        }
    }

    /**
     * Validate unique rule with better error handling
     */
    private function validateUnique($value, array $params, string $field): bool
    {
        if (empty($params[0])) {
            return false; // Table name is required
        }

        try {
            $query = query($params[0])->where($params[1] ?? $field, $value);

            // Handle exclude ID parameter
            if (isset($params[2]) && is_numeric($params[2])) {
                $query->where('id', '!=', intval($params[2]));
            }

            return $query->count() === 0;
        } catch (\Exception $e) {
            // Log error if needed and fail validation
            return false;
        }
    }

    /**
     * Validate exists rule with better error handling
     */
    private function validateExists($value, array $params, string $field): bool
    {
        if (empty($params[0])) {
            return false; // Table name is required
        }

        try {
            return query($params[0])->where($params[1] ?? $field, $value)->exists();
        } catch (\Exception $e) {
            // Log error if needed and fail validation
            return false;
        }
    }

    /**
     * Validate not_exists rule with better error handling
     */
    private function validateNotExists($value, array $params, string $field): bool
    {
        if (empty($params[0])) {
            return false; // Table name is required
        }

        try {
            return query($params[0])->where($params[1] ?? $field, $value)->notExists();
        } catch (\Exception $e) {
            // Log error if needed and fail validation
            return false;
        }
    }

    /** 
     * Compare value against minimum size
     * 
     * Compares a value against a minimum size, which can be numeric, string length,
     * or array size.
     * 
     * @param mixed $value The value to compare.
     * @param mixed $min The minimum size to compare against.
     * @return bool True if the value meets or exceeds the minimum size, false otherwise.
     */
    private function compareMin($value, $min, bool $isNumericField = false): bool
    {
        // ONLY treat as numeric if field explicitly has numeric validation rules
        if ($isNumericField && is_numeric($value)) {
            return (float) $value >= (float) $min;
        }

        // For file uploads (always check this before string check)
        if (is_array($value) && isset($value['size'])) {
            return (int) $value['size'] >= ((int) $min * 1024);
        }

        // For arrays
        if (is_array($value)) {
            return count($value) >= (int) $min;
        }

        // For everything else (including numeric strings without numeric rules), treat as string
        if (is_scalar($value)) {
            return strlen((string) $value) >= (int) $min;
        }

        return false;
    }

    /** 
     * Compare value against maximum size
     * 
     * Compares a value against a maximum size, which can be numeric, string length,
     * or array size.
     * 
     * @param mixed $value The value to compare.
     * @param mixed $max The maximum size to compare against.
     * @return bool True if the value is less than or equal to the maximum size, false otherwise.
     */
    private function compareMax($value, $max, bool $isNumericField = false): bool
    {
        // ONLY treat as numeric if field explicitly has numeric validation rules
        if ($isNumericField && is_numeric($value)) {
            return (float) $value <= (float) $max;
        }

        // For file uploads (always check this before string check)
        if (is_array($value) && isset($value['size'])) {
            return (int) $value['size'] <= ((int) $max * 1024);
        }

        // For arrays
        if (is_array($value)) {
            return count($value) <= (int) $max;
        }

        // For everything else (including numeric strings without numeric rules), treat as string
        if (is_scalar($value)) {
            return strlen((string) $value) <= (int) $max;
        }

        return false;
    }

    /**
     * Validate if a value is a valid date
     * 
     * @param mixed $value The value to validate
     * @return bool True if valid date
     */
    private function validateDate($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return false;
        }

        // Additional check to ensure it's a real date
        $date = date('Y-m-d', $timestamp);
        return strtotime($date) === strtotime(date('Y-m-d', $timestamp));
    }

    /**
     * Validate if a date is before another date
     * 
     * @param mixed $value The date value to validate
     * @param string $beforeDate The date to compare against
     * @return bool True if value is before the comparison date
     */
    private function validateBefore($value, string $beforeDate): bool
    {
        if (!$this->validateDate($value)) {
            return false;
        }

        $valueTimestamp = strtotime($value);
        $beforeTimestamp = strtotime($beforeDate);

        if ($valueTimestamp === false || $beforeTimestamp === false) {
            return false;
        }

        return $valueTimestamp < $beforeTimestamp;
    }

    /**
     * Validate if a date is after another date
     * 
     * @param mixed $value The date value to validate  
     * @param string $afterDate The date to compare against
     * @return bool True if value is after the comparison date
     */
    private function validateAfter($value, string $afterDate): bool
    {
        if (!$this->validateDate($value)) {
            return false;
        }

        $valueTimestamp = strtotime($value);
        $afterTimestamp = strtotime($afterDate);

        if ($valueTimestamp === false || $afterTimestamp === false) {
            return false;
        }

        return $valueTimestamp > $afterTimestamp;
    }

    /** 
     * Compare value against size
     * 
     * Compares a value against a specific size, which can be numeric, string length,
     * or array size.
     * 
     * @param mixed $value The value to compare.
     * @param mixed $size The size to compare against.
     * @return bool True if the value is equal to the specified size, false otherwise.
     */
    private function compareSize($value, $size, bool $isNumericField = false): bool
    {
        // ONLY treat as numeric if field explicitly has numeric validation rules  
        if ($isNumericField && is_numeric($value)) {
            return (float) $value == (float) $size;
        }

        // For file uploads (always check this before string check)
        if (is_array($value) && isset($value['size'])) {
            $fileSizeMB = $value['size'] / 1024 / 1024;
            $targetMB = $size / 1024;

            return $fileSizeMB >= $targetMB && $fileSizeMB < ($targetMB + 1);
        }

        // For arrays
        if (is_array($value)) {
            return count($value) == (int) $size;
        }

        // For everything else (including numeric strings without numeric rules), treat as string
        if (is_scalar($value)) {
            return strlen((string) $value) == (int) $size;
        }

        return false;
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
    private function validateBetween($value, array $params, bool $isNumericField = false): bool
    {
        if (count($params) < 2) {
            return false;
        }

        $min = $params[0];
        $max = $params[1];

        // ONLY treat as numeric if field explicitly has numeric validation rules
        if ($isNumericField && is_numeric($value)) {
            return (float) $value >= (float) $min && (float) $value <= (float) $max;
        }

        // For arrays, check count
        if (is_array($value)) {
            $count = count($value);
            return $count >= (int) $min && $count <= (int) $max;
        }

        // For everything else (including numeric strings without numeric rules), check string length
        if (is_scalar($value)) {
            $length = strlen((string) $value);
            return $length >= (int) $min && $length <= (int) $max;
        }

        return false;
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
        if (!is_array($file) || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        // Check if file exists and is readable
        if (!file_exists($file['tmp_name']) || !is_readable($file['tmp_name'])) {
            return false;
        }

        // Use getimagesize for better validation
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return false;
        }

        // Check for valid image MIME types
        $validImageTypes = [
            IMAGETYPE_JPEG,
            IMAGETYPE_PNG,
            IMAGETYPE_GIF,
            IMAGETYPE_WEBP,
            IMAGETYPE_BMP,
            IMAGETYPE_AVIF
        ];

        return in_array($imageInfo[2], $validImageTypes, true);
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
        if (!is_array($file) || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        // Check if file exists
        if (!file_exists($file['tmp_name']) || !is_readable($file['tmp_name'])) {
            return false;
        }

        // Get MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return false;
        }

        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo); // Close the resource to prevent leak

        if ($mimeType === false) {
            return false;
        }

        return in_array($mimeType, $allowedMimes, true) ||
            in_array(substr($mimeType, strpos($mimeType, '/') + 1), $allowedMimes, true);
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

        // Check minimum length
        if (mb_strlen($value, 'UTF-8') < $minLength) {
            return false;
        }

        // Check for uppercase letters
        if ($requireUppercase && !preg_match('/[\p{Lu}]/u', $value)) {
            return false;
        }

        // Check for lowercase letters  
        if ($requireLowercase && !preg_match('/[\p{Ll}]/u', $value)) {
            return false;
        }

        // Check for numbers
        if ($requireNumbers && !preg_match('/[\p{N}]/u', $value)) {
            return false;
        }

        // Check for symbols (any non-letter, non-number character)
        if ($requireSymbols && !preg_match('/[^\p{L}\p{N}]/u', $value)) {
            return false;
        }

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
     * Returns the validated data.
     * 
     * Returns the sanitized data after validation has been performed.
     * Throws an exception if no data has been validated yet.
     *
     * @return Sanitizer
     */
    public function validated(): Sanitizer
    {
        if (!isset($this->cleanData)) {
            throw new \RuntimeException('No data has been validated yet. Please call validate() first.');
        }

        return $this->cleanData;
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
     * @param array $value The value that failed validation.
     */
    private function addError(string $field, string $rule, array $params = [], $value = null): void
    {
        $prettyField = __(Str::headline($field));

        if (
            in_array($rule, ['min', 'minimum', 'max', 'maximum', 'length', 'size']) &&
            is_array($value) && isset($value['tmp_name']) && is_uploaded_file($value['tmp_name'])
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
            'alpha' => __($this->getErrorMessagePlaceholder('alpha', $field, 'The %s field must contain only letters.'), $prettyField),
            'alpha_num', 'alphanumeric' => __($this->getErrorMessagePlaceholder('alpha_num', $field, 'The %s field must contain only letters and numbers.'), $prettyField),
            'alpha_dash' => __($this->getErrorMessagePlaceholder('alpha_dash', $field, 'The %s field must contain only letters, numbers, dashes, and underscores.'), $prettyField),
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
