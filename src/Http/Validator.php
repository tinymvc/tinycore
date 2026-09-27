<?php

namespace Spark\Http;

use Spark\Contracts\Http\ValidatorContract;
use Spark\Support\Arr;
use Spark\Support\Str;
use Spark\Support\Traits\Macroable;
use function array_key_exists;
use function array_slice;
use function count;
use function in_array;
use function intval;
use function is_array;
use function is_bool;
use function is_int;
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

    /** @var Input */
    private Input $cleanData;

    /**
     * Constructs a new validator instance.
     * 
     * @param array $errors Optional array of errors to start with.
     */
    public function __construct(private array $errors = [])
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
     * Sometimes skips absent fields; nullable skips non-presence rules for null.
     * Neither rule makes a supplied empty value satisfy required or filled.
     * Nested fields use dot notation; a * segment validates each array item.
     * Rules may include parameter arrays, e.g. ['nullable', 'string', ['in' => ['a', 'b']]].
     *
     * @param array<string,mixed> $rules Array of validation rules where the key is the field name
     *                     and the value is an array of rules for that field.
     * @param array $inputData Array of input data to validate.
     * @return bool|Input Returns validated data as an Sanitizer instance if valid,
     *                             or false if validation fails.
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
            $ruleNames = array_column($fieldRules, 'name');
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

                // Apply validation rule
                $ruleValid = match ($ruleName) {
                    'required' => $has_valid_value,
                    'required_if' => $this->validateRequiredIf($ruleParams, $inputData, $has_valid_value, $rules),
                    'required_unless' => $this->validateRequiredUnless($ruleParams, $inputData, $has_valid_value, $rules),
                    'email', 'mail' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'url', 'link' => filter_var($value, FILTER_VALIDATE_URL) !== false,
                    'number', 'numeric', 'int', 'integer' => is_numeric($value),
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
                    'present' => $fieldExists, // Field must be present but can be empty
                    'filled' => $fieldExists ? $has_valid_value : true, // Field must be present and not empty if present
                    'accepted' => $this->validateAccepted($value),
                    'declined' => $this->validateDeclined($value),
                    'prohibited' => !$has_valid_value, // Field must be absent or empty
                    'file' => is_array($value) && $this->isUploadedFile($value),
                    'image' => $this->validateImage($value),
                    'mimes' => $this->validateMimes($value, $ruleParams),
                    'min_value' => isset($ruleParams[0]) ? is_numeric($value) && (float) $value >= (float) $ruleParams[0] : true,
                    'max_value' => isset($ruleParams[0]) ? is_numeric($value) && (float) $value <= (float) $ruleParams[0] : true,
                    'distinct' => isset($wildcardFields[$field])
                        ? $this->validateDistinctItem((string) $field, $value, $wildcardFields[$field], $inputData, $ruleParams)
                        : $this->validateDistinct($value),
                    'password' => is_scalar($value) && $this->validatePassword((string) $value, $ruleParams),
                    default => true // Default to true if rule is not recognized
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

    /** Split field paths while preserving escaped literal dots. */
    private function fieldSegments(string $field): array
    {
        $segments = [''];
        $index = 0;
        $escaped = false;

        foreach (str_split($field) as $character) {
            if ($character === '.' && !$escaped) {
                $segments[++$index] = '';
                continue;
            }

            $segments[$index] .= $character;
            $escaped = $character === '\\' && !$escaped;
        }

        return $segments;
    }

    private function escapeFieldSegment(string|int $segment): string
    {
        return strtr((string) $segment, ['\\' => '\\\\', '.' => '\\.', '*' => '\\*']);
    }

    private function unescapeFieldSegment(string $segment): string
    {
        return strtr($segment, ['\\\\' => '\\', '\\.' => '.', '\\*' => '*']);
    }

    /** Keep array containers as well as leaves, so array and presence rules still work. */
    private function flattenInputData(array $data, array $path = []): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $segments = [...$path, $this->escapeFieldSegment($key)];
            $result[implode('.', $segments)] = $value;
            if (is_array($value)) {
                $result += $this->flattenInputData($value, $segments);
            }
        }
        return $result;
    }

    /** Resolve wildcard segments from existing arrays, retaining missing explicit children. */
    private function expandFieldPaths(array $segments, mixed $data, array $path = [], array $keys = []): array
    {
        if ($segments === []) {
            return [implode('.', $path) => $keys];
        }

        $segment = array_shift($segments);
        if ($segment === '*') {
            $result = [];
            foreach (is_array($data) ? $data : [] as $key => $value) {
                $key = $this->escapeFieldSegment($key);
                $result += $this->expandFieldPaths($segments, $value, [...$path, $key], [...$keys, $key]);
            }
            return $result;
        }

        $key = $this->unescapeFieldSegment($segment);
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return $this->expandFieldPaths($segments, $value, [...$path, $segment], $keys);
    }

    /** Expand item rules and their sibling references without rewriting literal rule values. */
    private function expandFieldRules(array $rules, array $inputData): array
    {
        $expanded = $wildcardFields = [];
        foreach ($rules as $field => $fieldRules) {
            $fieldRules = $this->normalizeFieldRules($fieldRules);
            $paths = $this->expandFieldPaths($this->fieldSegments((string) $field), $inputData);

            foreach ($paths as $path => $keys) {
                $resolvedRules = [];
                foreach ($fieldRules as $rule) {
                    $name = $rule['name'];
                    $parameters = $rule['parameters'];
                    if (isset($parameters[0]) && is_scalar($parameters[0]) && $keys !== [] && in_array($name, ['required_if', 'required_unless', 'same', 'equal', 'same_as', 'gt', 'gte', 'lt', 'lte', 'ls'], true)) {
                        $segments = $this->fieldSegments(trim($parameters[0]));

                        $index = 0;
                        foreach ($segments as &$segment) {
                            if ($segment === '*') {
                                $segment = $keys[$index++] ?? '*';
                            }
                        }
                        unset($segment);

                        $parameters[0] = implode('.', $segments);
                        $rule['parameters'] = $parameters;
                    }

                    $resolvedRules[] = $rule;
                }

                $expanded[$path] = [...($expanded[$path] ?? []), ...$resolvedRules];
                if ($keys !== []) {
                    $wildcardFields[$path] ??= array_keys($paths);
                }
            }
        }

        return [$expanded, $wildcardFields];
    }

    /** Rebuild the original array shape instead of returning flattened field names. */
    private function setValidatedField(array &$data, string $field, mixed $value): void
    {
        if (!str_contains($field, '\\')) {
            Arr::set($data, $field, $value);
            return;
        }

        $target = &$data;
        foreach ($this->fieldSegments($field) as $segment) {
            $key = $this->unescapeFieldSegment($segment);
            if (!is_array($target)) {
                $target = [];
            }

            $target = &$target[$key];
        }

        $target = $value;
    }

    private function fieldColumn(string $field): string
    {
        $segments = $this->fieldSegments($field);

        return $this->unescapeFieldSegment(end($segments));
    }

    /** Compare a wildcard item against the other items matched by the same rule. */
    private function validateDistinctItem(string $field, mixed $value, array $fields, array $inputData, array $params): bool
    {
        foreach ($fields as $otherField) {
            if ((string) $otherField === $field || !array_key_exists($otherField, $inputData)) {
                continue;
            }

            $other = $inputData[$otherField];
            if (in_array('ignore_case', $params, true) && is_string($value) && is_string($other)) {
                if (mb_strtolower($value) === mb_strtolower($other)) {
                    return false;
                }
            } elseif (in_array('strict', $params, true) ? $value === $other : $value == $other) {
                return false;
            }
        }
        return true;
    }

    /**
     * Parse string rules and associative parameter arrays into a common representation.
     * Array parameters retain their original types and literal delimiters.
     *
     * @param mixed $fieldRules
     * @return array<int, array{name: string, parameters: array}>
     */
    private function normalizeFieldRules(mixed $fieldRules): array
    {
        if (is_string($fieldRules)) {
            $fieldRules = explode('|', $fieldRules);
        }

        if (!is_array($fieldRules)) {
            return [];
        }

        $rules = [];

        foreach ($fieldRules as $name => $rule) {
            if (is_string($name)) {
                $name = strtolower(trim($name));
                if ($name !== '') {
                    $rules[] = ['name' => $name, 'parameters' => is_array($rule) ? array_values($rule) : [$rule]];
                }
                continue;
            }

            if (is_array($rule)) {
                $rules = [...$rules, ...$this->normalizeFieldRules($rule)];
                continue;
            }

            if (!is_scalar($rule)) {
                continue;
            }

            $rule = trim((string) $rule);
            if ($rule !== '') {
                [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, null);
                $rules[] = [
                    'name' => strtolower(trim($name)),
                    'parameters' => $parameters === null ? [] : array_map('trim', explode(',', $parameters)),
                ];
            }
        }

        return $rules;
    }

    /**
     * Require a value only when the other field exists and matches a listed value.
     */
    private function validateRequiredIf(array $params, array $inputData, bool $hasValidValue, array $rules): bool
    {
        if (count($params) < 2) {
            return false;
        }

        return !array_key_exists($params[0], $inputData)
            || !$this->matchesDependentValues($params, $inputData, $rules)
            || $hasValidValue;
    }

    /**
     * Require a value unless the other field matches, including missing/null fields.
     */
    private function validateRequiredUnless(array $params, array $inputData, bool $hasValidValue, array $rules): bool
    {
        if (count($params) < 2) {
            return false;
        }

        return $this->matchesDependentValues($params, $inputData, $rules) || $hasValidValue;
    }

    /**
     * Compare conditional rule values without treating null or booleans as strings.
     */
    private function matchesDependentValues(array $params, array $inputData, array $rules): bool
    {
        $otherValue = $inputData[$params[0]] ?? null;
        $expectedValues = array_slice($params, 1);
        $otherRuleNames = array_column($rules[$params[0]] ?? [], 'name');

        if (is_bool($otherValue) || array_intersect(['boolean', 'bool'], $otherRuleNames)) {
            $expectedValues = array_map(fn($value) => match ($value) {
                'true' => true,
                'false' => false,
                default => $value,
            }, $expectedValues);
        }

        if ($otherValue === null) {
            $expectedValues = array_map(fn($value) => is_string($value) && strtolower($value) === 'null' ? null : $value, $expectedValues);
        }

        return in_array($otherValue, $expectedValues, is_bool($otherValue) || $otherValue === null);
    }

    /**
     * Determine whether a value satisfies required/filled (zero and false are not empty).
     */
    private function hasValidValue(mixed $value): bool
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return false;
        }

        if (is_countable($value) && count($value) === 0) {
            return false;
        }

        // PHP represents an unselected upload as a non-empty array.
        if (is_array($value) && isset($value['tmp_name'], $value['error'])) {
            return $value['error'] !== UPLOAD_ERR_NO_FILE && $value['tmp_name'] !== '';
        }

        return true;
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

        return array_intersect(array_column($rules, 'name'), $numericRules) !== [];
    }

    /**
     * Validate Unicode letters, marks, and optional numbers/dashes.
     * The ascii option restricts input through Str's portable-ascii integration.
     */
    private function validateAlphabetic(mixed $value, string $rule, array $params): bool
    {
        if (!is_string($value) && ($rule === 'alpha' || !is_numeric($value))) {
            return false;
        }

        $value = (string) $value;
        if (in_array('ascii', $params, true) && !Str::isAscii($value)) {
            return false;
        }

        $characters = match ($rule) {
            'alpha' => '\\pL\\pM',
            'alpha_num', 'alphanumeric' => '\\pL\\pM\\pN',
            'alpha_dash' => '\\pL\\pM\\pN_-',
        };

        return preg_match("/\\A[$characters]+\\z/u", $value) === 1;
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
        if (empty($params[0]) || !is_scalar($value)) {
            return false; // No pattern provided
        }

        $pattern = (string) $params[0];
        $value = (string) $value;

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
            return query($params[0])->where($params[1] ?? $field, $value)->doesntExist();
        } catch (\Exception $e) {
            // Log error if needed and fail validation
            return false;
        }
    }

    /**
     * Compare compatible field values: numbers, text lengths, array counts, or file sizes.
     * Numeric parameters also act as literal thresholds when no such field exists.
     */
    private function compareField(mixed $value, array $params, array $inputData, string $rule): bool
    {
        $field = $params[0] ?? null;
        if (count($params) !== 1 || (!is_string($field) && !is_int($field) && !is_float($field)) || $field === '') {
            return false;
        }

        if (array_key_exists((string) $field, $inputData)) {
            $other = $inputData[(string) $field];
        } elseif (is_numeric($field) && is_numeric($value)) {
            $other = $field;
        } else {
            return false;
        }

        if (is_numeric($value) || is_numeric($other)) {
            if (!is_numeric($value) || !is_numeric($other)) {
                return false;
            }
            // Keep integer values and numeric strings intact instead of casting to float.
            $left = $value;
            $right = $other;
        } elseif (is_string($value) && is_string($other)) {
            $left = mb_strlen($value, 'UTF-8');
            $right = mb_strlen($other, 'UTF-8');
        } elseif (is_array($value) && is_array($other)) {
            $isFile = array_key_exists('tmp_name', $value);
            $otherIsFile = array_key_exists('tmp_name', $other);
            if ($isFile || $otherIsFile) {
                if (!$isFile || !$otherIsFile || !$this->isUploadedFile($value) || !$this->isUploadedFile($other)) {
                    return false;
                }
                if (!is_file($value['tmp_name']) || !is_file($other['tmp_name'])) {
                    return false;
                }
                $left = filesize($value['tmp_name']);
                $right = filesize($other['tmp_name']);
                if ($left === false || $right === false) {
                    return false;
                }
            } else {
                $left = count($value);
                $right = count($other);
            }
        } else {
            return false;
        }

        return match ($rule) {
            'gt' => $left > $right,
            'gte' => $left >= $right,
            'lt', 'ls' => $left < $right,
            'lte' => $left <= $right,
        };
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
        if ($value instanceof \DateTimeInterface) {
            return true;
        }

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

        $valueTimestamp = $this->getDateTimestamp($value);
        $beforeTimestamp = $this->getDateTimestamp($beforeDate);

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

        $valueTimestamp = $this->getDateTimestamp($value);
        $afterTimestamp = $this->getDateTimestamp($afterDate);

        if ($valueTimestamp === false || $afterTimestamp === false) {
            return false;
        }

        return $valueTimestamp > $afterTimestamp;
    }

    private function getDateTimestamp($value): false|int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (!is_string($value)) {
            return false;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? false : (int) $timestamp;
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
     * Validate JSON value.
     *
     * @param mixed $value
     * @return bool
     */
    private function isValidJson($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Validate image file
     * 
     * Validates if a file is a valid image by checking its MIME type.
     * 
     * @param mixed $file The uploaded file array containing 'tmp_name'.
     * @return bool True if the file is a valid image, false otherwise.
     */
    private function validateImage($file): bool
    {
        if (!is_array($file) || !$this->isUploadedFile($file)) {
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
        if (!is_array($file) || !$this->isUploadedFile($file)) {
            return false;
        }

        // Check if file has an allowed type list.
        if (empty($allowedMimes)) {
            return false;
        }

        $tmpPath = $file['tmp_name'];
        if (!is_string($tmpPath)) {
            return false;
        }

        $normalizedAllowedMimes = array_values(array_unique(array_filter(array_map(static function ($mime) {
            $normalized = strtolower((string) trim((string) ltrim((string) $mime, '.')));

            return $normalized !== '' ? $normalized : null;
        }, $allowedMimes))));
        if (empty($normalizedAllowedMimes)) {
            return false;
        }

        $mimeType = \Spark\Utils\FileManager::mimeType($tmpPath);

        if ($mimeType === false) {
            return false;
        }

        $mimeType = strtolower((string) $mimeType);
        $slashPosition = strpos($mimeType, '/');
        $extension = $slashPosition === false ? '' : strtolower((string) substr($mimeType, $slashPosition + 1));

        return in_array($mimeType, $normalizedAllowedMimes, true)
            || in_array($extension, $normalizedAllowedMimes, true);
    }

    /**
     * Checks whether a file array references a readable file path.
     *
     * @param array $file
     * @return bool
     */
    private function isUploadedFile(array $file): bool
    {
        $tmpPath = $file['tmp_name'] ?? null;

        if (!is_string($tmpPath) || $tmpPath === '') {
            return false;
        }

        if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
            return false;
        }

        if (array_key_exists('error', $file) && is_int($file['error'])) {
            return $file['error'] === UPLOAD_ERR_OK;
        }

        return true;
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
     * Returns the sanitized data after validation has been performed.
     * Throws an exception if no data has been validated yet.
     * 
     * @param string|null $key Optional key to retrieve a specific value from the validated data.
     * @param mixed $default Default value to return if the key does not exist.
     *
     * @return ($key is null ? \Spark\Http\Input : mixed)
     */
    public function validated(?string $key = null, $default = null): Input
    {
        if (!isset($this->cleanData)) {
            throw new \RuntimeException('No data has been validated yet. Please call validate() first.');
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

    private function validateAccepted(mixed $value): bool
    {
        return in_array($value, ['yes', 'on', 1, '1', true, 'true'], true);
    }

    private function validateDeclined(mixed $value): bool
    {
        return in_array($value, ['no', 'off', 0, '0', false, 'false'], true);
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
     * Merges custom error messages with existing ones.
     *
     * @param array $messages Associative array of rule names and their custom error messages to merge.
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
        $field = $this->unescapeFieldSegment($field);
        $prettyField = __(Str::headline($field));

        if (
            in_array($rule, ['min', 'minimum', 'max', 'maximum', 'length', 'size']) &&
            is_array($value) && $this->isUploadedFile($value)
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
        $field2 = strtolower("$field.$rule"); // make the field name in lowercase

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
