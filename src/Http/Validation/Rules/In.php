<?php

namespace Spark\Http\Validation\Rules;

use Spark\Contracts\Http\RuleContract;
use Spark\Contracts\Support\Arrayable;
use Stringable;
use function in_array;
use function is_scalar;

class In implements RuleContract, Stringable
{
    /** @var array The allowed values. */
    protected array $values;

    /**
     * Create a new in rule instance.
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
            return true;
        }

        if (is_scalar($value)) {
            return in_array($value, $this->values, false);
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
        return __('The %s field must be one of the following values: %s.', [':attribute', implode(', ', $this->values)]);
    }

    /**
     * Convert the rule to its string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return 'in:' . implode(',', $this->values);
    }
}
