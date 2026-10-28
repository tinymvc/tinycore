<?php

namespace Spark\Http\Validation\Rules;

use Closure;
use Spark\Contracts\Http\RuleContract;
use Stringable;
use Throwable;

class Unique implements RuleContract, Stringable
{
    /** @var mixed The ID to ignore. */
    protected mixed $ignoreId = null;

    /** @var string The ignore column name. */
    protected string $idColumn = 'id';

    /** @var list<array{string, mixed}> Additional where clauses. */
    protected array $wheres = [];

    /** @var list<Closure> Custom query callbacks. */
    protected array $queryCallbacks = [];

    /**
     * Create a new unique rule instance.
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
     * Ignore the given ID during uniqueness validation.
     *
     * @param mixed $id The primary key or value to ignore.
     * @param string $idColumn The primary key column name (defaults to 'id').
     * @return static
     */
    public function ignore(mixed $id, string $idColumn = 'id'): static
    {
        $this->ignoreId = $id;
        $this->idColumn = $idColumn;
        return $this;
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

            if ($this->ignoreId !== null) {
                $query->where($this->idColumn, '!=', $this->ignoreId);
            }

            foreach ($this->wheres as [$col, $val]) {
                $query->where($col, $val);
            }

            foreach ($this->queryCallbacks as $callback) {
                $callback($query);
            }

            return $query->count() === 0;
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
        return __('The %s field must be unique in the %s table.', [':attribute', $this->table]);
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

        $rule = 'unique:' . $this->table . ',' . ($this->column ?: 'NULL');

        if ($this->ignoreId !== null) {
            $rule .= ',' . $this->ignoreId . ',' . $this->idColumn;
        }

        return $rule;
    }
}
