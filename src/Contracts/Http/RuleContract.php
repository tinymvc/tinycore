<?php

namespace Spark\Contracts\Http;

/**
 * Interface RuleContract
 *
 * Defines the contract for custom and built-in validation rules.
 *
 * @package Spark\Contracts\Http
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
interface RuleContract
{
    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute The attribute name being validated
     * @param mixed $value The value being validated
     * @return bool
     */
    public function passes(string $attribute, mixed $value): bool;

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string;
}
