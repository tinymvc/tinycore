<?php

namespace Spark\Contracts\Http;

/**
 * Interface for the validator utility contract.
 *
 * The validator utility contract provides methods to validate input data
 * against specified rules.
 */
interface ValidatorContract
{
    /**
     * Validates input data against specified rules.
     *
     * @param array<string,mixed> $rules Array of validation rules where the key is the field name
     *                     and the value is an array of rules for that field.
     * @param array $inputData Array of input data to validate.
     * @return bool|InputContract Returns validated data as an SanitizerContract instance if valid,
     */
    public function validate(array $rules, array $inputData): bool|InputContract;

    /**
     * Returns all validation errors.
     *
     * @return array Associative array of field names and error messages.
     */
    public function getErrors(): array;

    /**
     * Checks if there are any validation errors.
     *
     * @return bool True if there are validation errors, false otherwise.
     */
    public function hasErrors(): bool;

    /**
     * Checks if the validation passed without errors.
     *
     * @return bool True if validation passed, false otherwise.
     */
    public function passes(): bool;

    /**
     * Checks if the validation failed with errors.
     *
     * @return bool True if validation failed, false otherwise.
     */
    public function fails(): bool;

    /**
     * Returns validation errors for a specific field or all errors if no field is specified.
     *
     * @param null|string $field The name of the field to get errors for, or null to get all errors.
     * @return array An array of error messages for the specified field, or an associative array of all errors if no field is specified.
     */
    public function errors(null|string $field = null): array;

    /**
     * Returns the first validation error message for a specific field or the first error overall if no field is specified.
     *
     * @param null|string $field The name of the field to get the first error for, or null to get the first error overall.
     * @return string|null The first error message for the specified field, the first error overall if no field is specified, or null if there are no errors.
     */
    public function getFirstError(): ?string;

    /**
     * Returns the validated data as an InputContract instance.
     *
     * @return InputContract An instance of InputContract containing the validated data.
     */
    public function validated(): \Spark\Http\Input;
}