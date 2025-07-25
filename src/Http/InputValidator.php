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
                    'confirmed' => ($has_valid_value || $is_required) ? $value == ($inputData[$field . '_confirmation'] ?? null) : true,
                    'in', 'exists' => ($has_valid_value || $is_required) ? in_array($value, $ruleParams, true) : true,
                    'not_in', 'not_exists' => ($has_valid_value || $is_required) ? !in_array($value, $ruleParams, true) : true,
                    'regex' => ($has_valid_value || $is_required) ? preg_match($ruleParams[0], $value) : true,
                    'unique' => ($has_valid_value || $is_required) ? query($ruleParams[0])->where($ruleParams[1] ?? $field, $value)->where(isset($ruleParams[2]) ? ("id != " . intval($ruleParams[2])) : null)->count() === 0 : true,
                    default => true
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
            default => __($this->getErrorMessagePlaceholder('default', $field, 'The %s field has an invalid value.'), $prettyField)
        };
    }

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
