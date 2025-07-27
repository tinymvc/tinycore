<?php

namespace Spark\Http\Traits;

use Spark\Foundation\Application;
use Spark\Helpers\HttpRequestErrors;
use Spark\Http\InputSanitizer;
use Spark\Http\InputValidator;

/**
 * Trait for the validation helper.
 * 
 * The trait contains methods for validating the request
 * with given rules and returning the validated attributes.
 * 
 * @package Spark\Helpers
 */
trait ValidateRequest
{
    /**
     * The error object.
     *
     * This property contains the error messages
     * when the validation fails.
     *
     * @var object
     */
    private object $errorObject;

    /**
     * Validates the request with given rules.
     * 
     * The method gets the input attributes from the current request and
     * validates them with the given rules. If the validation fails, the
     * method redirects the user back to the previous page with the
     * validation errors. If the request wants a JSON response, the method
     * returns the validation errors as a JSON response.
     * 
     * @param array $rules
     *   The validation rules.
     *
     * @return InputSanitizer
     *   Returns the validated attributes as an InputSanitizer instance.
     */
    public function validate(array $rules): InputSanitizer
    {
        $attributes = $this->all(array_keys($rules)); // Get the attributes from the current request
        $validator = Application::$app->get(InputValidator::class); // Get the validator instance

        if (!$validator->validate($rules, $this->all())) { // Validate the request
            $errors = $validator->getErrors(); // Get the errors as an array

            // If the request wants a JSON response
            if ($this->isFirelineRequest()) {
                $errorHtml = '<ul>' // Build the error HTML
                    . collect(array_merge(...array_values($errors)))
                        ->map(fn($error) => "<li>{$error}</li>")
                        ->join('') // Join the errors into a string
                    . '</ul>';

                // Return the errors as a JSON response
                json(['status' => 'error', 'message' => $errorHtml])
                    ->send();
            } elseif ($this->expectsJson()) {
                // Return the errors as a JSON response
                json([
                    'message' => rtrim($validator->getFirstError(), '.') . '.' . (count($errors) > 1 ? ' (and ' . count($errors) . ' more errors)' : ''),
                    'errors' => $errors
                ], 422)
                    ->send();
            } else {
                // Store the errors in the session flash data
                back()
                    ->with('errors', ['attributes' => $attributes, 'messages' => $errors])
                    ->send(); // Redirect the user back to the previous page
            }
            exit; // Exit the script to prevent further execution
        }

        return new InputSanitizer($attributes); // Return the validated attributes
    }

    /**
     * Get the error object.
     *
     * The method returns the error object from the session flash data.
     * The error object contains the error messages and attributes from the previous request.
     *
     * @return \Spark\Helpers\HttpRequestErrors
     *   The error object.
     */
    public function getErrorObject(): HttpRequestErrors
    {
        return $this->errorObject ??= app(HttpRequestErrors::class);
    }

    /**
     * Get the errors from the current request.
     *
     * @param null|array|string $field The field name to retrieve the error messages for.
     *                                  If null, all error object will be returned.
     * @return object|bool An object containing the error messages from the current request.
     */
    public function errors(null|array|string $field = null): mixed
    {
        if ($field !== null) {
            foreach ((array) $field as $name) {
                if ($this->getErrorObject()->has($name)) {
                    return true;
                }
            }
            return false;
        }

        return $this->getErrorObject();
    }

    /**
     * Get the value of a field from the previous request using the old input.
     *
     * @param string $field The name of the field to retrieve.
     * @param ?string $default The default value to return if the field is not found.
     * @return string|null The value of the field from the previous request, or the default value if not found.
     */
    public function old(string $field, ?string $default = null): ?string
    {
        return $this->getErrorObject()->getOld($field, $default);
    }
}
