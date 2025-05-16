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
     * @return bool|array Returns validated data as an array if valid, or false if validation fails.
     */
    public function validate(string|array $rules, array $inputData): bool|array
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
                    'email' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_EMAIL) : true,
                    'url' => ($has_valid_value || $is_required) ? filter_var($value, FILTER_VALIDATE_URL) : true,
                    'number' => ($has_valid_value || $is_required) ? is_numeric($value) : true,
                    'array' => ($has_valid_value || $is_required) ? is_array($value) : true,
                    'text', 'char' => ($has_valid_value || $is_required) ? is_string($value) : true,
                    'min', 'minimum' => ($has_valid_value || $is_required) ? strlen($value ?? '') >= (int) $ruleParams[0] : true,
                    'max', 'maximum' => ($has_valid_value || $is_required) ? strlen($value ?? '') <= (int) $ruleParams[0] : true,
                    'length', 'size' => ($has_valid_value || $is_required) ? strlen($value ?? '') == (int) $ruleParams[0] : true,
                    'equal', 'same', 'same_as' => ($has_valid_value || $is_required) ? $value == ($inputData[$ruleParams[0]] ?? null) : true,
                    'confirmed' => ($has_valid_value || $is_required) ? $value == ($inputData[$field . '_confirmation'] ?? null) : true,
                    'in', 'exists' => ($has_valid_value || $is_required) ? in_array($value, $ruleParams, true) : true,
                    'not_in', 'not_exists' => ($has_valid_value || $is_required) ? !in_array($value, $ruleParams, true) : true,
                    'regex' => ($has_valid_value || $is_required) ? preg_match($ruleParams[0], $value) : true,
                    'unique' => ($has_valid_value || $is_required) ? query($ruleParams[0])->where($ruleParams[1] ?? $field, $value)->count() === 0 : true,
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
            'required' => __("The %s field is required.", $prettyField),
            'email' => __("The %s field must be a valid email address.", $prettyField),
            'url' => __("The %s field must be a valid URL.", $prettyField),
            'number' => __("The %s field must be a number.", $prettyField),
            'array' => __("The %s field must be an array.", $prettyField),
            'text', 'char' => __("The %s field must be a text.", $prettyField),
            'min', 'minimum' => __("The %s field must be at least %s characters long.", [$prettyField, $params[0] ?? 0]),
            'max', 'maximum' => __("The %s field must not exceed %s characters.", [$prettyField, $params[0] ?? 0]),
            'length', 'size' => __("The %s field must be %s characters.", [$prettyField, $params[0] ?? 0]),
            'equal', 'same', 'same_as' => __("The %s field must be equal to %s field.", [$prettyField, __(Str::headline($params[0] ?? ''))]),
            'confirmed' => __("The %s field must be confirmed.", $prettyField),
            'in', 'exists' => __("The %s field must be one of the following values: %s.", [$prettyField, implode(', ', $params)]),
            'not_in', 'not_exists' => __("The %s field must not be one of the following values: %s.", [$prettyField, implode(', ', $params)]),
            'regex' => __("The %s field must match the pattern: %s.", [$prettyField, $params[0] ?? '']),
            'unique' => __("The %s field must be unique in the %s table.", [$prettyField, $params[0] ?? '']),
            default => __("The %s field has an invalid value.", $prettyField)
        };
    }
}
