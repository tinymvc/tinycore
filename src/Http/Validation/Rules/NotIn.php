<?php

namespace Spark\Http\Validation\Rules;

use Spark\Contracts\Http\RuleContract;
use Spark\Contracts\Support\Arrayable;
use Stringable;
use function in_array;
use function is_scalar;

class NotIn implements RuleContract, Stringable
{
    /** @var array The disallowed values. */
    protected array $values;

    /**
     * Create a new not_in rule instance.
     *
     * @param array|Arrayable $values
     */
    public function __construct(array|Arrayable $values)
    {
        $this->values = $values instanceof Arrayable ? $values->toArray() : array_values($values);
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
        if (in_array($value, $this->values, true)) {
            return false;
        }

        if (is_scalar($value)) {
            return !in_array($value, $this->values, false);
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
        return __('The %s field must not be one of the following values: %s.', [':attribute', implode(', ', $this->values)]);
    }

    /**
     * Convert the rule to its string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return 'not_in:' . implode(',', $this->values);
    }
}
