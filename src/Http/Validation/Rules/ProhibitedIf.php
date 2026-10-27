<?php

namespace Spark\Http\Validation\Rules;

use Closure;
use Spark\Contracts\Http\RuleContract;
use function count;
use function is_callable;
use function is_countable;
use function is_string;
use function trim;

class ProhibitedIf implements RuleContract
{
    /** @var bool|Closure */
    protected bool|Closure $condition;

    /**
     * Create a new prohibited_if rule instance.
     *
     * @param bool|callable $condition
     */
    public function __construct(bool|callable $condition)
    {
        $this->condition = is_callable($condition) ? Closure::fromCallable($condition) : (bool) $condition;
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
        $isProhibited = $this->condition instanceof Closure ? ($this->condition)() : $this->condition;

        if (!$isProhibited) {
            return true;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return true;
        }

        if (is_countable($value) && count($value) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return __('The %s field is prohibited.', [':attribute']);
    }
}
