<?php

namespace Spark\Foundation\Exceptions;

use Exception;

class ValidationException extends Exception
{
    public function __construct(
        string $message,
        protected array $errors = [],
        protected array $attributes = [],
        int $code = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function make(
        string $message = 'Validation failed.',
        array $errors = [],
        array $attributes = []
    ): self {
        return new self(
            message: $message,
            errors: $errors,
            attributes: $attributes
        );
    }

    public static function withMessages(array $messages, array $attributes = []): self
    {
        return new self(
            message: 'Validation failed.',
            errors: $messages,
            attributes: $attributes
        );
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }
}