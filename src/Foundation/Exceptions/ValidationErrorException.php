<?php

namespace Spark\Foundation\Exceptions;

use Exception;

class ValidationErrorException extends Exception
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

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }
}