<?php

namespace Sparks\Foundation\Http;

use Spark\Exceptions\Http\AuthorizationException;
use Spark\Http\Request;

/**
 * Class FormRequest
 *
 * This class is responsible for handling form requests, including authorization and validation.
 * It extends the base Request class and provides methods for defining authorization logic, 
 * validation rules, custom messages, and attributes.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class FormRequest extends Request
{
    /**
     * Create a new form request instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(); // Call the parent constructor to initialize the request.

        if (!$this->authorize()) {
            $this->failedAuthorization();
        }

        $this->validate(
            rules: $this->rules(),
            attributes: $this->attributes(),
            messages: $this->messages(),
        );

        $this->passedValidation(); // This method can be used to handle passed validation.
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // For simple apps, you might return true, but
        // in a real app, you would add logic here.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // Define your validation rules here. For example:
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes(): array
    {
        return $this->all();
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @return void
     */
    public function failedAuthorization(): void
    {
        throw new AuthorizationException('This action is unauthorized.');
    }

    /**
     * Handle a passed validation attempt.
     *
     * @return void
     */
    public function passedValidation(): void
    {
        // This method can be used to handle passed validation.
    }
}