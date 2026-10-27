<?php

namespace Spark\Http\Validation\Rules;

use Spark\Contracts\Http\RuleContract;
use function is_scalar;
use function mb_strlen;
use function preg_match;

class Password implements RuleContract
{
    /** @var int Minimum password length. */
    protected int $min = 8;

    /** @var bool Require at least one uppercase letter. */
    protected bool $requireUppercase = false;

    /** @var bool Require at least one lowercase letter. */
    protected bool $requireLowercase = false;

    /** @var bool Require at least one numeric digit. */
    protected bool $requireNumbers = false;

    /** @var bool Require at least one symbol. */
    protected bool $requireSymbols = false;

    /** @var string|null Specific failure message. */
    protected ?string $failedRuleMessage = null;

    /**
     * Create a new password rule instance with minimum length.
     *
     * @param int $length
     * @return static
     */
    public static function min(int $length = 8): static
    {
        $instance = new static();
        $instance->min = $length;
        return $instance;
    }

    /**
     * Require at least one uppercase and one lowercase letter.
     *
     * @return static
     */
    public function letters(): static
    {
        $this->requireUppercase = true;
        $this->requireLowercase = true;
        return $this;
    }

    /**
     * Require at least one uppercase and one lowercase letter.
     *
     * @return static
     */
    public function mixedCase(): static
    {
        return $this->letters();
    }

    /**
     * Require at least one numeric digit.
     *
     * @return static
     */
    public function numbers(): static
    {
        $this->requireNumbers = true;
        return $this;
    }

    /**
     * Require at least one special character / symbol.
     *
     * @return static
     */
    public function symbols(): static
    {
        $this->requireSymbols = true;
        return $this;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     * @param mixed $value
     * @return bool
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if (!is_scalar($value)) {
            $this->failedRuleMessage = __('The %s field must be a valid string.', [':attribute']);
            return false;
        }

        $value = (string) $value;

        if (mb_strlen($value, 'UTF-8') < $this->min) {
            $this->failedRuleMessage = __('The %s field must be at least %s characters long.', [':attribute', $this->min]);
            return false;
        }

        if ($this->requireUppercase && !preg_match('/[\p{Lu}]/u', $value)) {
            $this->failedRuleMessage = __('The %s field must contain at least one uppercase letter.', [':attribute']);
            return false;
        }

        if ($this->requireLowercase && !preg_match('/[\p{Ll}]/u', $value)) {
            $this->failedRuleMessage = __('The %s field must contain at least one lowercase letter.', [':attribute']);
            return false;
        }

        if ($this->requireNumbers && !preg_match('/[\p{N}]/u', $value)) {
            $this->failedRuleMessage = __('The %s field must contain at least one number.', [':attribute']);
            return false;
        }

        if ($this->requireSymbols && !preg_match('/[^\p{L}\p{N}]/u', $value)) {
            $this->failedRuleMessage = __('The %s field must contain at least one symbol.', [':attribute']);
            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return $this->failedRuleMessage ?? __('The %s field must meet password requirements.', [':attribute']);
    }
}
