<?php

namespace Spark\Http\Validation\Rules;

use Closure;
use Spark\Contracts\Http\RuleContract;
use Stringable;
use Throwable;

class NotExists implements RuleContract, Stringable
{
    /** @var array<string,mixed> Additional where clauses. */
    protected array $wheres = [];

    /** @var Closure|null Custom query callback. */
    protected ?Closure $queryCallback = null;

    /**
     * Create a new not_exists rule instance.
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
            $this->queryCallback = $column;
        } else {
            $this->wheres[$column] = $value;
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

            foreach ($this->wheres as $col => $val) {
                $query->where($col, $val);
            }

            if ($this->queryCallback !== null) {
                ($this->queryCallback)($query);
            }

            return $query->doesntExist();
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
        return __('The %s field must not exist in the %s table.', [':attribute', $this->table]);
    }

    /**
     * Convert the rule to its string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return 'not_exists:' . $this->table . ',' . ($this->column ?: 'NULL');
    }
}
