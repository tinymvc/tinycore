<?php

namespace Spark\Http\Validation\Concerns;

use DateTime;
use DateTimeInterface;
use Psr\Http\Message\UploadedFileInterface;
use Spark\Support\Str;
use function array_column;
use function array_intersect;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_unique;
use function count;
use function date;
use function in_array;
use function ini_get;
use function ini_set;
use function is_array;
use function is_bool;
use function is_countable;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_last_error;
use function mb_strlen;
use function mb_strtolower;
use function preg_match;
use function strtotime;
use function strtolower;
use function trigger_error;
use function trim;
use const E_USER_WARNING;
use const JSON_ERROR_NONE;
use const SORT_REGULAR;
use const UPLOAD_ERR_NO_FILE;

trait ValidatesAttributes
{
    /**
     * Determine whether a value satisfies required/filled (zero and false are not empty).
     *
     * @param mixed $value
     * @return bool
     */
    protected function hasValidValue(mixed $value): bool
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return false;
        }

        if (is_countable($value) && count($value) === 0) {
            return false;
        }

        if ($value instanceof UploadedFileInterface) {
            return $value->getError() !== UPLOAD_ERR_NO_FILE;
        }

        // PHP represents an unselected upload as a non-empty array.
        if (is_array($value) && isset($value['tmp_name'], $value['error'])) {
            return $value['error'] !== UPLOAD_ERR_NO_FILE && $value['tmp_name'] !== '';
        }

        return true;
    }

    /**
     * Check if field has numeric validation rules.
     *
     * @param array $rules Array of validation rules for a field
     * @return bool True if field has numeric validation rules
     */
    protected function hasNumericValidation(array $rules): bool
    {
        $numericRules = ['number', 'numeric', 'int', 'integer', 'float', 'decimal'];

        foreach ($rules as $rule) {
            if (is_string($rule['name']) && in_array($rule['name'], $numericRules, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Require a value only when the other field exists and matches a listed value.
     *
     * @param array $params
     * @param array $inputData
     * @param bool $hasValidValue
     * @param array $rules
     * @return bool
     */
    protected function validateRequiredIf(array $params, array $inputData, bool $hasValidValue, array $rules): bool
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
     *
     * @param array $params
     * @param array $inputData
     * @param bool $hasValidValue
     * @param array $rules
     * @return bool
     */
    protected function validateRequiredUnless(array $params, array $inputData, bool $hasValidValue, array $rules): bool
    {
        if (count($params) < 2) {
            return false;
        }

        return $this->matchesDependentValues($params, $inputData, $rules) || $hasValidValue;
    }

    /**
     * Compare conditional rule values without treating null or booleans as strings.
     *
     * @param array $params
     * @param array $inputData
     * @param array $rules
     * @return bool
     */
    protected function matchesDependentValues(array $params, array $inputData, array $rules): bool
    {
        $otherValue = $inputData[$params[0]] ?? null;
        $expectedValues = array_slice($params, 1);
        $otherRuleNames = array_filter(array_column($rules[$params[0]] ?? [], 'name'), 'is_string');

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
     * Validate accepted rule.
     *
     * @param mixed $value
     * @return bool
     */
    protected function validateAccepted(mixed $value): bool
    {
        return in_array($value, ['yes', 'on', 1, '1', true, 'true'], true);
    }

    /**
     * Validate declined rule.
     *
     * @param mixed $value
     * @return bool
     */
    protected function validateDeclined(mixed $value): bool
    {
        return in_array($value, ['no', 'off', 0, '0', false, 'false'], true);
    }

    /**
     * Validate Unicode letters, marks, and optional numbers/dashes.
     *
     * @param mixed $value
     * @param string $rule
     * @param array $params
     * @return bool
     */
    protected function validateAlphabetic(mixed $value, string $rule, array $params): bool
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
     * Validate 'in' rule with better type handling.
     *
     * @param mixed $value
     * @param array $allowedValues
     * @return bool
     */
    protected function validateIn(mixed $value, array $allowedValues): bool
    {
        if (in_array($value, $allowedValues, true)) {
            return true;
        }

        if (is_scalar($value)) {
            return in_array($value, $allowedValues, false);
        }

        return false;
    }

    /**
     * Validate equal rule with better type handling.
     *
     * @param mixed $value1
     * @param mixed $value2
     * @return bool
     */
    protected function validateEqual(mixed $value1, mixed $value2): bool
    {
        if ($value1 === $value2) {
            return true;
        }

        if (is_scalar($value1) && is_scalar($value2)) {
            return $value1 == $value2;
        }

        return false;
    }

    /**
     * Validate regex rule with pattern validation and ReDoS protection.
     *
     * @param mixed $value
     * @param array $params
     * @return bool
     */
    protected function validateRegex(mixed $value, array $params): bool
    {
        if (empty($params[0]) || !is_scalar($value)) {
            return false;
        }

        $pattern = (string) $params[0];
        $value = (string) $value;

        if (@preg_match($pattern, '') === false) {
            trigger_error("Invalid regex pattern provided: $pattern", E_USER_WARNING);
            return false;
        }

        $originalTimeout = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');

        try {
            $result = @preg_match($pattern, (string) $value);
            return $result === 1;
        } finally {
            ini_set('pcre.backtrack_limit', $originalTimeout);
        }
    }

    /**
     * Validate unique database rule.
     *
     * @param mixed $value
     * @param array $params
     * @param string $field
     * @return bool
     */
    protected function validateUnique(mixed $value, array $params, string $field): bool
    {
        if (empty($params[0])) {
            return false;
        }

        try {
            $column = empty($params[1]) || $params[1] === 'NULL' ? $field : $params[1];
            $query = query($params[0])->where($column, $value);

            if (isset($params[2]) && $params[2] !== '' && $params[2] !== 'NULL') {
                $query->where($params[3] ?? 'id', '!=', $params[2]);
            }

            return $query->count() === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Validate exists database rule.
     *
     * @param mixed $value
     * @param array $params
     * @param string $field
     * @return bool
     */
    protected function validateExists(mixed $value, array $params, string $field): bool
    {
        if (empty($params[0])) {
            return false;
        }

        try {
            $column = empty($params[1]) || $params[1] === 'NULL' ? $field : $params[1];
            return query($params[0])->where($column, $value)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Validate not_exists database rule.
     *
     * @param mixed $value
     * @param array $params
     * @param string $field
     * @return bool
     */
    protected function validateNotExists(mixed $value, array $params, string $field): bool
    {
        if (empty($params[0])) {
            return false;
        }

        try {
            $column = empty($params[1]) || $params[1] === 'NULL' ? $field : $params[1];
            return query($params[0])->where($column, $value)->doesntExist();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Compare compatible field values: numbers, text lengths, array counts, or file sizes.
     *
     * @param mixed $value
     * @param array $params
     * @param array $inputData
     * @param string $rule
     * @return bool
     */
    protected function compareField(mixed $value, array $params, array $inputData, string $rule): bool
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
            $left = $value;
            $right = $other;
        } elseif (is_string($value) && is_string($other)) {
            $left = mb_strlen($value, 'UTF-8');
            $right = mb_strlen($other, 'UTF-8');
        } elseif ($this->isUploadedFile($value) || $this->isUploadedFile($other)) {
            if (!$this->isUploadedFile($value) || !$this->isUploadedFile($other)) {
                return false;
            }
            $left = $this->uploadedFileSize($value);
            $right = $this->uploadedFileSize($other);
            if ($left === null || $right === null) {
                return false;
            }
        } elseif (is_array($value) && is_array($other)) {
            $left = count($value);
            $right = count($other);
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
     * Compare value against minimum size.
     *
     * @param mixed $value
     * @param mixed $min
     * @param bool $isNumericField
     * @return bool
     */
    protected function compareMin(mixed $value, mixed $min, bool $isNumericField = false): bool
    {
        if ($isNumericField && is_numeric($value)) {
            return (float) $value >= (float) $min;
        }

        if ($this->isUploadedFile($value)) {
            $bytes = $this->uploadedFileSize($value);
            return $bytes !== null && $bytes >= ((float) $min * 1024);
        }

        if (is_array($value)) {
            return count($value) >= (int) $min;
        }

        if (is_scalar($value)) {
            return strlen((string) $value) >= (int) $min;
        }

        return false;
    }

    /**
     * Compare value against maximum size.
     *
     * @param mixed $value
     * @param mixed $max
     * @param bool $isNumericField
     * @return bool
     */
    protected function compareMax(mixed $value, mixed $max, bool $isNumericField = false): bool
    {
        if ($isNumericField && is_numeric($value)) {
            return (float) $value <= (float) $max;
        }

        if ($this->isUploadedFile($value)) {
            $bytes = $this->uploadedFileSize($value);
            return $bytes !== null && $bytes <= ((float) $max * 1024);
        }

        if (is_array($value)) {
            return count($value) <= (int) $max;
        }

        if (is_scalar($value)) {
            return strlen((string) $value) <= (int) $max;
        }

        return false;
    }

    /**
     * Compare value against specific size.
     *
     * @param mixed $value
     * @param mixed $size
     * @param bool $isNumericField
     * @return bool
     */
    protected function compareSize(mixed $value, mixed $size, bool $isNumericField = false): bool
    {
        if ($isNumericField && is_numeric($value)) {
            return (float) $value == (float) $size;
        }

        if ($this->isUploadedFile($value)) {
            $bytes = $this->uploadedFileSize($value);
            return $bytes !== null && $bytes == ((float) $size * 1024);
        }

        if (is_array($value)) {
            return count($value) == (int) $size;
        }

        if (is_scalar($value)) {
            return strlen((string) $value) == (int) $size;
        }

        return false;
    }

    /**
     * Validate between rule for numeric and string values.
     *
     * @param mixed $value
     * @param array $params
     * @param bool $isNumericField
     * @return bool
     */
    protected function validateBetween(mixed $value, array $params, bool $isNumericField = false): bool
    {
        if (count($params) < 2) {
            return false;
        }

        return $this->compareMin($value, $params[0], $isNumericField)
            && $this->compareMax($value, $params[1], $isNumericField);
    }

    /**
     * Validate if a value is a valid date.
     *
     * @param mixed $value
     * @return bool
     */
    protected function validateDate(mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return false;
        }

        $date = date('Y-m-d', $timestamp);
        return strtotime($date) === strtotime(date('Y-m-d', $timestamp));
    }

    /**
     * Validate if a date is before another date.
     *
     * @param mixed $value
     * @param string $beforeDate
     * @return bool
     */
    protected function validateBefore(mixed $value, string $beforeDate): bool
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
     * Validate if a date is after another date.
     *
     * @param mixed $value
     * @param string $afterDate
     * @return bool
     */
    protected function validateAfter(mixed $value, string $afterDate): bool
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

    /**
     * Get timestamp from date value or string.
     *
     * @param mixed $value
     * @return false|int
     */
    protected function getDateTimestamp(mixed $value): false|int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (!is_string($value)) {
            return false;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? false : (int) $timestamp;
    }

    /**
     * Validate date format.
     *
     * @param string $value
     * @param string $format
     * @return bool
     */
    protected function validateDateFormat(string $value, string $format): bool
    {
        $date = DateTime::createFromFormat($format, $value);
        return $date && $date->format($format) === $value;
    }

    /**
     * Validate JSON value.
     *
     * @param mixed $value
     * @return bool
     */
    protected function isValidJson(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Validate distinct values in an array.
     *
     * @param mixed $value
     * @return bool
     */
    protected function validateDistinct(mixed $value): bool
    {
        if (is_array($value)) {
            return count($value) === count(array_unique($value, SORT_REGULAR));
        }

        return true;
    }

    /**
     * Compare a wildcard item against the other items matched by the same rule.
     *
     * @param string $field
     * @param mixed $value
     * @param array $fields
     * @param array $inputData
     * @param array $params
     * @return bool
     */
    protected function validateDistinctItem(string $field, mixed $value, array $fields, array $inputData, array $params): bool
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
     * Validate password strength.
     *
     * @param string $value
     * @param array $params
     * @return bool
     */
    protected function validatePassword(string $value, array $params): bool
    {
        $minLength = (int) ($params[0] ?? 8);
        $requireUppercase = in_array('uppercase', $params, true);
        $requireLowercase = in_array('lowercase', $params, true);
        $requireNumbers = in_array('numbers', $params, true);
        $requireSymbols = in_array('symbols', $params, true);

        if (mb_strlen($value, 'UTF-8') < $minLength) {
            return false;
        }

        if ($requireUppercase && !preg_match('/[\p{Lu}]/u', $value)) {
            return false;
        }

        if ($requireLowercase && !preg_match('/[\p{Ll}]/u', $value)) {
            return false;
        }

        if ($requireNumbers && !preg_match('/[\p{N}]/u', $value)) {
            return false;
        }

        if ($requireSymbols && !preg_match('/[^\p{L}\p{N}]/u', $value)) {
            return false;
        }

        return true;
    }
}
