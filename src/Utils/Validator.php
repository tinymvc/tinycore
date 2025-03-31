<?php

namespace Spark\Utils;

/**
 * Class Validator
 * 
 * Validator class provides methods to validate data based on specified rules.
 * Includes validation methods for common data types and constraints.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Validator
{
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
     * @param array $rules Array of validation rules where the key is the field name
     *                     and the value is an array of rules for that field.
     * @param array $inputData Array of input data to validate.
     * @return bool|array Returns validated data as an array if valid, or false if validation fails.
     */
    public function validate(array $rules, array $inputData): bool|array
    {
        $validData = [];
        foreach ($rules as $field => $fieldRules) {
            $value = $inputData[$field] ?? null;
            $valid = true;

            // Check if field is required
            $is_required = in_array('required', $fieldRules, true);

            // Loop through field rules
            foreach ($fieldRules as $rule) {
                // Parse rule name and parameters
                $ruleName = $rule;
                $ruleParams = [];
                if (strpos($rule, ':') !== false) {
                    [$ruleName, $ruleParams] = explode(':', $rule, 2);
                    $ruleParams = explode(',', $ruleParams);
                }

                // Check if value is valid
                $has_valid_value = $value === null ? false : (is_array($value) ? !empty($value) : '' !== $value);

                // Apply validation rule
                $valid = match ($ruleName) {
                    'required' => $has_valid_value,
                    'email' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_EMAIL) : true,
                    'url' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_URL) : true,
                    'number' => ($has_valid_value || $is_required) ? is_numeric($value) : true,
                    'array' => ($has_valid_value || $is_required) ? is_array($value) : true,
                    'text' => ($has_valid_value || $is_required) ? is_string($value) : true,
                    'min' => ($has_valid_value || $is_required) ? strlen($value ?? '') >= (int) $ruleParams[0] : true,
                    'max' => ($has_valid_value || $is_required) ? strlen($value ?? '') <= (int) $ruleParams[0] : true,
                    'length' => ($has_valid_value || $is_required) ? strlen($value ?? '') == (int) $ruleParams[0] : true,
                    'equal' => ($has_valid_value || $is_required) ? $value == ($inputData[$ruleParams[0]] ?? null) : true,
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
        return empty($this->errors) ? $validData : false;
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

    /** @Add helper methods for this validator object */

    /**
     * Adds an error message for a failed validation rule.
     *
     * @param string $field Field name that failed validation.
     * @param string $rule Validation rule that failed.
     * @param array $params Parameters for the rule, if any.
     */
    private function addError(string $field, string $rule, array $params = []): void
    {
        $prettyField = __($this->parseFieldName($field));

        // Error messages for each validation rule
        $this->errors[$field][] = match ($rule) {
            'required' => __("The %s field is required.", $prettyField),
            'email' => __("The %s field must be a valid email address.", $prettyField),
            'url' => __("The %s field must be a valid URL.", $prettyField),
            'number' => __("The %s field must be a number.", $prettyField),
            'array' => __("The %s field must be an array.", $prettyField),
            'text' => __("The %s field must be a text.", $prettyField),
            'min' => __("The %s field must be at least %s characters long.", [$prettyField, $params[0] ?? 0]),
            'max' => __("The %s field must not exceed %s characters.", [$prettyField, $params[0] ?? 0]),
            'length' => __("The %s field must be %s characters.", [$prettyField, $params[0] ?? 0]),
            'equal' => __("The %s field must be equal to %s field.", [$prettyField, __($this->parseFieldName($params[0] ?? ''))]),
            default => __("The %s field has an invalid value.", $prettyField)
        };
    }

    /**
     * Converts a field name into a human-readable format.
     *
     * @param string $field Field name to convert.
     * @return string Pretty field name.
     */
    private function parseFieldName(string $field): string
    {
        return strtolower(str_replace(['-', '_', '.'], ' ', $field));
    }
}
