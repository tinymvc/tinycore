<?php

namespace Spark\Http\Validation;

use Closure;
use RuntimeException;
use Spark\Contracts\Http\ValidatorContract;
use Spark\Contracts\Http\RuleContract;
use Spark\Http\Input;
use Spark\Http\Validation\Concerns\ExpandsFields;
use Spark\Http\Validation\Concerns\FormatsMessages;
use Spark\Http\Validation\Concerns\ValidatesAttributes;
use Spark\Http\Validation\Concerns\ValidatesFiles;
use Spark\Http\Validation\Rules\ProhibitedIf;
use Spark\Http\Validation\Rules\RequiredIf;
use Spark\Http\Validation\Rules\RequiredUnless;
use Spark\Support\Str;
use Spark\Support\Traits\Macroable;
use function array_column;
use function array_intersect;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_pop;
use function count;
use function ctype_digit;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function is_string;
use function preg_match;
use function strlen;
use function strtolower;
use function strtoupper;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function trim;
use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_INT;
use const FILTER_VALIDATE_IP;
use const FILTER_VALIDATE_MAC;
use const FILTER_VALIDATE_URL;

/**
 * Class Validator
 *
 * Core validator class providing methods to validate input data against specified rules.
 *
 * @package Spark\Http\Validation
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Validator implements ValidatorContract
{
    use Macroable;
    use ExpandsFields;
    use FormatsMessages;
    use ValidatesFiles;
    use ValidatesAttributes;

    /** @var Input Validated clean data. */
    protected Input $cleanData;

    /**
     * Constructs a new validator instance.
     *
     * @param array $errors Optional array of errors to start with.
     */
    public function __construct(protected array $errors = [])
    {
    }

    /**
     * Static method to create a new validator instance and validate data.
     *
     * @param null|array $rules An array of validation rules to apply.
     * @param array|null $data An optional array of data to validate. If not provided, it will use all request data.
     * @return static Returns a validator instance with validation results.
     */
    public static function make(null|array $rules = null, null|array $data = null): static
    {
        $validator = new static();
        if ($rules !== null) {
            $inputData = $data === null ? request()->all() : $data;
            $validator->validate($rules, $inputData);
        }
        return $validator;
    }

    /**
     * Validates input data against specified rules.
     *
     * @param array<string,mixed> $rules Array of validation rules.
     * @param array $inputData Array of input data to validate.
     * @return bool|Input Returns validated data as an Input instance if valid, or false if validation fails.
     */
    public function validate(array $rules, array $inputData): bool|Input
    {
        $validData = [];
        $this->errors = [];
        unset($this->cleanData);

        [$rules, $wildcardFields] = $this->expandFieldRules($rules, $inputData);
        $inputData = $this->flattenInputData($inputData);
        $parentFields = [];

        foreach (array_keys($rules) as $field) {
            $segments = $this->fieldSegments((string) $field);
            while (count($segments) > 1) {
                array_pop($segments);
                $parentFields[implode('.', $segments)] = true;
            }
        }

        foreach ($rules as $field => $fieldRules) {
            $value = $inputData[$field] ?? null;
            $fieldExists = array_key_exists($field, $inputData);
            $valid = true;
            $ruleNames = array_filter(array_column($fieldRules, 'name'), 'is_string');
            $nullable = in_array('nullable', $ruleNames, true);

            // Sometimes makes the entire rule set conditional on the field being supplied.
            if (!$fieldExists && in_array('sometimes', $ruleNames, true)) {
                continue;
            }

            if (empty($fieldRules)) {
                if ($fieldExists) {
                    $this->setValidatedField($validData, (string) $field, $value);
                }
                continue;
            }

            // Check if field has numeric validation rules
            $is_numeric_field = $this->hasNumericValidation($fieldRules);
            $has_valid_value = $this->hasValidValue($value);

            // Loop through field rules
            foreach ($fieldRules as $rule) {
                $ruleName = $rule['name'];
                $ruleParams = $rule['parameters'];

                // Handle RuleContract instances
                if ($ruleName instanceof RuleContract) {
                    $isImplicit = $ruleName instanceof RequiredIf || $ruleName instanceof RequiredUnless || $ruleName instanceof ProhibitedIf;

                    if (!$isImplicit && (!$fieldExists || (is_string($value) && trim($value) === '') || ($nullable && $value === null))) {
                        continue;
                    }

                    if (!$ruleName->passes((string) $field, $value)) {
                        $valid = false;
                        $this->addCustomError((string) $field, $ruleName->message());
                        if ($isImplicit) {
                            break;
                        }
                    }
                    continue;
                }

                // Handle Closure rules: function (string $attribute, mixed $value, Closure $fail)
                if ($ruleName instanceof Closure) {
                    if (!$fieldExists || (is_string($value) && trim($value) === '') || ($nullable && $value === null)) {
                        continue;
                    }

                    $closureFailed = false;
                    $ruleName((string) $field, $value, function (string $message) use ($field, &$valid, &$closureFailed) {
                        $valid = false;
                        $closureFailed = true;
                        $this->addCustomError((string) $field, $message);
                    });

                    if ($closureFailed) {
                        continue;
                    }
                    continue;
                }

                if (in_array($ruleName, ['sometimes', 'nullable'], true)) {
                    continue;
                }

                // Presence rules still run on missing, null, and blank values.
                $implicit = in_array($ruleName, [
                    'required',
                    'required_if',
                    'required_unless',
                    'present',
                    'filled',
                    'accepted',
                    'declined',
                ], true);

                // Null and empty arrays are supplied values, not missing fields.
                if (!$implicit && (!$fieldExists || (is_string($value) && trim($value) === '') || ($nullable && $value === null))) {
                    continue;
                }

                // Do not query the database after another rule has rejected this field.
                if (!$valid && in_array($ruleName, ['unique', 'exists', 'not_exists'], true)) {
                    continue;
                }

                // Apply built-in validation rule
                $ruleValid = match ($ruleName) {
                    'required' => $has_valid_value,
                    'required_if' => $this->validateRequiredIf($ruleParams, $inputData, $has_valid_value, $rules),
                    'required_unless' => $this->validateRequiredUnless($ruleParams, $inputData, $has_valid_value, $rules),
                    'email', 'mail' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'url', 'link' => filter_var($value, FILTER_VALIDATE_URL) !== false,
                    'number', 'numeric' => is_numeric($value),
                    'int', 'integer' => !is_bool($value) && filter_var($value, FILTER_VALIDATE_INT) !== false,
                    'array', 'list' => is_array($value),
                    'text', 'char', 'string' => is_string($value),
                    'min', 'minimum' => isset($ruleParams[0]) ? $this->compareMin($value, $ruleParams[0], $is_numeric_field) : true,
                    'max', 'maximum' => isset($ruleParams[0]) ? $this->compareMax($value, $ruleParams[0], $is_numeric_field) : true,
                    'length', 'size' => isset($ruleParams[0]) ? $this->compareSize($value, $ruleParams[0], $is_numeric_field) : true,
                    'gt', 'gte', 'lt', 'lte', 'ls' => $this->compareField($value, $ruleParams, $inputData, $ruleName),
                    'equal', 'same', 'same_as' => isset($ruleParams[0]) ? $this->validateEqual($value, $inputData[$ruleParams[0]] ?? null) : true,
                    'confirmed' => $value == ($inputData["{$field}_confirmation"] ?? null),
                    'in' => $this->validateIn($value, $ruleParams),
                    'not_in' => !$this->validateIn($value, $ruleParams),
                    'regex' => $this->validateRegex($value, $ruleParams),
                    'unique' => $this->validateUnique($value, $ruleParams, $this->fieldColumn((string) $field)),
                    'exists' => $this->validateExists($value, $ruleParams, $this->fieldColumn((string) $field)),
                    'not_exists' => $this->validateNotExists($value, $ruleParams, $this->fieldColumn((string) $field)),
                    'boolean', 'bool' => in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false', 'TRUE', 'FALSE', 'on', 'off', 'yes', 'no', 'YES', 'NO'], true),
                    'float', 'decimal' => is_numeric($value),
                    'alpha', 'alpha_num', 'alphanumeric', 'alpha_dash' => $this->validateAlphabetic($value, $ruleName, $ruleParams),
                    'ascii' => is_string($value) && Str::isAscii($value),
                    'digits' => isset($ruleParams[0]) ? is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) == (int) $ruleParams[0] : true,
                    'digits_between' => isset($ruleParams[0], $ruleParams[1]) ? is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) >= (int) $ruleParams[0] && strlen((string) $value) <= (int) $ruleParams[1] : true,
                    'min_digits' => isset($ruleParams[0]) ? is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) >= (int) $ruleParams[0] : true,
                    'max_digits' => isset($ruleParams[0]) ? is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) <= (int) $ruleParams[0] : true,
                    'date' => $this->validateDate($value),
                    'date_format' => isset($ruleParams[0]) ? is_string($value) && $this->validateDateFormat((string) $value, $ruleParams[0]) : true,
                    'before' => isset($ruleParams[0]) ? $this->validateBefore($value, $ruleParams[0]) : true,
                    'after' => isset($ruleParams[0]) ? $this->validateAfter($value, $ruleParams[0]) : true,
                    'between' => $this->validateBetween($value, $ruleParams, $is_numeric_field),
                    'json' => $this->isValidJson($value),
                    'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
                    'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
                    'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
                    'mac_address' => filter_var($value, FILTER_VALIDATE_MAC) !== false,
                    'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1,
                    'lowercase' => is_string($value) && $value === strtolower($value),
                    'uppercase' => is_string($value) && $value === strtoupper($value),
                    'starts_with' => isset($ruleParams[0]) && $ruleParams[0] !== '' && is_scalar($value) && str_starts_with((string) $value, $ruleParams[0]),
                    'ends_with' => isset($ruleParams[0]) && $ruleParams[0] !== '' && is_scalar($value) && str_ends_with((string) $value, $ruleParams[0]),
                    'contains' => isset($ruleParams[0]) && $ruleParams[0] !== '' && is_scalar($value) && str_contains((string) $value, $ruleParams[0]),
                    'not_contains' => isset($ruleParams[0]) && $ruleParams[0] !== '' && is_scalar($value) && !str_contains((string) $value, $ruleParams[0]),
                    'present' => $fieldExists,
                    'filled' => $fieldExists ? $has_valid_value : true,
                    'accepted' => $this->validateAccepted($value),
                    'declined' => $this->validateDeclined($value),
                    'prohibited' => !$has_valid_value,
                    'file' => $this->isUploadedFile($value),
                    'image' => $this->validateImage($value),
                    'mimes' => $this->validateMimes($value, $ruleParams),
                    'min_value' => isset($ruleParams[0]) ? is_numeric($value) && (float) $value >= (float) $ruleParams[0] : true,
                    'max_value' => isset($ruleParams[0]) ? is_numeric($value) && (float) $value <= (float) $ruleParams[0] : true,
                    'distinct' => isset($wildcardFields[$field])
                        ? $this->validateDistinctItem((string) $field, $value, $wildcardFields[$field], $inputData, $ruleParams)
                        : $this->validateDistinct($value),
                    'password' => is_scalar($value) && $this->validatePassword((string) $value, $ruleParams),
                    default => true
                };

                // Add error if rule validation fails
                if (!$ruleValid) {
                    $valid = false;
                    $this->addError($field, $ruleName, $ruleParams, $value);

                    // Once a presence requirement fails, further checks cannot fix it.
                    if ($implicit) {
                        break;
                    }
                }
            }

            // Store valid data if field passed all rules
            if ($valid && array_key_exists($field, $inputData)) {
                // Let child rules select the validated keys of an explicitly validated array.
                if (is_array($value) && isset($parentFields[$field]) && array_intersect(['array', 'list'], $ruleNames)) {
                    continue;
                }
                $this->setValidatedField($validData, (string) $field, $value);
            }
        }

        // Return validated data or false if there are errors
        return empty($this->errors) ? $this->cleanData = new Input($validData) : false;
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
     * Checks if there are any validation errors.
     *
     * @return bool True if there are validation errors, false otherwise.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Checks if validation has passed.
     *
     * @return bool True if there are no validation errors, false otherwise.
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Checks if validation has failed.
     *
     * @return bool True if there are validation errors, false otherwise.
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Returns validation errors for a specific field or all fields.
     *
     * @param string|null $field The field name to get errors for, or null for all fields.
     * @return array Array of error messages for the specified field or all fields.
     */
    public function errors(null|string $field = null): array
    {
        if ($field !== null) {
            return $this->errors[$field] ?? [];
        }

        return $this->errors;
    }

    /**
     * Returns the validated data.
     *
     * @param string|null $key Optional key to retrieve a specific value from the validated data.
     * @param mixed $default Default value to return if the key does not exist.
     * @return ($key is null ? Input : mixed)
     */
    public function validated(?string $key = null, $default = null): mixed
    {
        if (!isset($this->cleanData)) {
            throw new RuntimeException('No data has been validated yet. Please call validate() first.');
        }

        if ($key !== null) {
            return $this->cleanData->get($key, $default);
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
        if (empty($this->errors)) {
            return null;
        }

        $first = $this->errors[array_key_first($this->errors)] ?? null;
        if (!is_array($first)) {
            return is_string($first) ? $first : null;
        }

        foreach ($first as $error) {
            if (is_string($error)) {
                return $error;
            }
        }

        return null;
    }
}
