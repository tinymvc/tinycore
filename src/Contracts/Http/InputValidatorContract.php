<?php

namespace Spark\Contracts\Http;

use Spark\Http\InputSanitizer;

/**
 * Interface for the validator utility contract.
 *
 * The validator utility contract provides methods to validate input data
 * against specified rules.
 */
interface InputValidatorContract
{
    /**
     * Validates input data against specified rules.
     *
     * @param string|array $rules Array of validation rules where the key is the field name
     *                     and the value is an array of rules for that field.
     * @param array $inputData Array of input data to validate.
     * @return bool|InputSanitizer Returns validated data as an InputSanitizer instance if valid,
     */
    public function validate(string|array $rules, array $inputData): bool|InputSanitizer;

    /**
     * Returns all validation errors.
     *
     * @return array Associative array of field names and error messages.
     */
    public function getErrors(): array;
}