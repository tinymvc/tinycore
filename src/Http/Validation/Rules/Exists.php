<?php

namespace Spark\Http\Validation\Rules;

use Closure;
use Spark\Contracts\Http\RuleContract;
use Stringable;
use Throwable;

class Exists implements RuleContract, Stringable
{
    /** @var list<array{string, mixed}> Additional where clauses. */
    protected array $wheres = [];

    /** @var list<Closure> Custom query callbacks. */
    protected array $queryCallbacks = [];

    /**
     * Create a new exists rule instance.
     *
     * @param string $table The database table name.
     * @param string|null $column The database column name.
     */
    public function __construct(
        protected string $table,
        protected ?string $column = null
    ) {
    }

    /**
     * Add an additional query condition or callback.
     *
     * @param string|Closure $column
     * @param mixed $value
     * @return static
     */
    public function where(string|Closure $column, mixed $value = null): static
    {
        if ($column instanceof Closure) {
            $this->queryCallbacks[] = $column;
        } else {
            $this->wheres[] = [$column, $value];
        }

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
        $column = $this->column ?: $attribute;

        try {
            $query = query($this->table)->where($column, $value);

            foreach ($this->wheres as [$col, $val]) {
                $query->where($col, $val);
            }

            foreach ($this->queryCallbacks as $callback) {
                $callback($query);
            }

            return $query->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return __('The %s field must exist in the %s table.', [':attribute', $this->table]);
    }

    /**
     * Convert the rule to its string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->wheres !== [] || $this->queryCallbacks !== []) {
            throw new \LogicException('Pass constrained database rules as objects to preserve their query conditions.');
        }

        return 'exists:' . $this->table . ',' . ($this->column ?: 'NULL');
    }
}
