<?php

namespace Spark\Http\Traits;

use Spark\Foundation\Application;
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
     * @return array
     *   The validated attributes.
     */
    public function validate(array $rules): array
    {
        $attributes = $this->all(array_keys($rules)); // Get the attributes from the current request
        $validator = Application::$app->get(InputValidator::class); // Get the validator instance

        if (!$validator->validate($rules, $attributes)) { // Validate the attributes
            $errors = $validator->getErrors(); // Get the errors as an array

            // If the request wants a JSON response
            if ($this->isFirelineRequest()) {
                $errorHtml = '<ul>' // Build the error HTML
                    . collect(array_merge(...array_values($errors)))
                        ->map(fn($error) => "<li>{$error}</li>")
                        ->toString()
                    . '</ul>';

                // Return the errors as a JSON response
                response()
                    ->json(['status' => 'error', 'message' => $errorHtml])
                    ->send();
                exit;
            } elseif ($this->expectsJson()) {
                // Return the errors as a JSON response
                response()
                    ->json(['message' => __('Validation failed'), 'errors' => $errors])
                    ->setStatusCode(422)
                    ->send();
                exit;
            }

            // Store the errors in the session flash data
            response()
                ->with('errors', ['attributes' => $attributes, 'messages' => $errors])
                ->back(); // Redirect the user back to the previous page
        }

        return $attributes; // Return the validated attributes
    }

    /**
     * Get the error object.
     *
     * The method returns the error object from the session flash data.
     * The error object contains the error messages and attributes from the previous request.
     *
     * @return object
     *   The error object.
     */
    public function getErrorObject(): object
    {
        return $this->errorObject ??= $this->buildErrorObject();
    }

    /**
     * Build and return an error object.
     *
     * This method creates an anonymous class instance that encapsulates
     * error messages and attributes from the session flash data. The returned
     * object provides methods to access and manipulate these error messages
     * and attributes, allowing retrieval of specific error messages, checking
     * for any existing errors, and obtaining all error messages as an array.
     *
     * @return object
     *   An object containing methods to interact with error messages and attributes.
     */
    private function buildErrorObject(): object
    {
        return new class {
            /**
             * @var array Stores error messages for fields
             */
            private array $messages = [];

            /**
             * @var array Stores attributes from the previous request
             */
            private array $attributes = [];

            /**
             * Construct the error object.
             *
             * This method sets the error messages and attributes from the session
             * flash data. The messages and attributes are stored in the session
             * as an array with the keys 'messages' and 'attributes' respectively.
             *
             * @return void
             */
            public function __construct()
            {
                // Get the error messages and attributes from the session
                $errors = session()->getFlash('errors', []);

                $this->messages = $errors['messages'] ?? [];
                $this->attributes = $errors['attributes'] ?? [];
            }

            /**
             * Get the old value of the given field.
             * 
             * The method returns the old value of the given field from the
             * previous request. If the field does not exist, the method
             * returns the given default value.
             * 
             * @param string $field
             *   The field name.
             * @param string $default
             *   The default value to return if the field does not exist.
             * 
             * @return string|null
             *   The old value of the given field.
             */
            public function getOld(string $field, string $default = null): ?string
            {
                return $this->attributes[$field] ?? $default;
            }

            /**
             * Check if there are any error messages.
             *
             * @return bool
             */
            public function any(): bool
            {
                return count($this->messages) > 0;
            }

            /**
             * Retrieve all error messages as an indexed array.
             *
             * @param bool $merge Merge all error messages into a single array
             * @return array
             */
            public function all($merge = true): array
            {
                if ($merge) {
                    return array_merge(...array_values($this->messages));
                }

                return $this->messages;
            }

            /**
             * Get the error message for a specific field.
             *
             * @param string $field
             * @return array|null
             */
            public function error(string $field): ?array
            {
                return $this->messages[$field] ?? null;
            }

            /**
             * Determine if an error message exists for a given field.
             *
             * @param string $field The field name to check for an error message.
             * @return bool True if an error message exists for the field, false otherwise.
             */
            public function has(string $field): bool
            {
                return isset($this->messages[$field]);
            }

            /**
             * Get the error message for a specific field.
             * 
             * This method is an alias for the error method.
             * 
             * @param string $field The field name to retrieve the error message for.
             * 
             * @return array|null
             *   The error messages for the given field, or null if no error exists for the field.
             */
            public function get(string $field): ?array
            {
                return $this->error($field);
            }

            /**
             * Get the first error message from the given field.
             *
             * @param string $field The field name to retrieve the error message from.
             * 
             * @return string|null
             *   The first error message for the given field, or null if no errors exist for the field.
             */
            public function first(string $field): ?string
            {
                return $this->messages[$field][0] ?? null;
            }

            /**
             * Get the first error message from the collection.
             *
             * @return string|null
             */
            public function getFirstError(): ?string
            {
                return current($this->messages);
            }

            /**
             * Convert the error object to a string.
             *
             * This method returns the first error message from the collection
             * if any errors exist, otherwise it returns an empty string.
             *
             * @return string The first error message or an empty string if no errors exist.
             */
            public function __toString()
            {
                return $this->any() ? $this->getFirstError() : '';
            }
        };
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
     * @param string $default The default value to return if the field is not found.
     * @return string|null The value of the field from the previous request, or the default value if not found.
     */
    public function old(string $field, string $default = null): ?string
    {
        return $this->getErrorObject()->getOld($field, $default);
    }
}