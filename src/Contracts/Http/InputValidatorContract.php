<?php

namespace Spark\Contracts\Http;

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
     * @param array $rules Array of validation rules where the key is the field name
     *                     and the value is an array of rules for that field.
     * @param array $inputData Array of input data to validate.
     * @return bool|array Returns validated data as an array if valid, or false if validation fails.
     */
    public function validate(array $rules, array $inputData): bool|array;

    /**
     * Returns all validation errors.
     *
     * @return array Associative array of field names and error messages.
     */
    public function getErrors(): array;
}