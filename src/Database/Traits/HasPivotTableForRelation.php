<?php
namespace Spark\Database\Traits;

use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Trait HasPivotTableForRelation
 * 
 * This trait provides functionality for handling pivot tables in relationships.
 * It allows you to specify additional fields and conditions for the pivot table
 * when defining relationships between models.
 * 
 * @package Spark\Database\Traits
 */
trait HasPivotTableForRelation
{
    /** @var array Fields to be appended to the relationship when retrieving related models. */
    protected array $pivotFields = [];

    /** @var array Additional conditions for the pivot table in the relationship. */
    protected array $wherePivot = [];

    /**
     * Build the pivot fields for the relationship.
     * 
     * @return null|string A comma-separated string of pivot fields, or null if no pivot fields are defined.
     */
    protected function buildPivotFields(): null|string
    {
        if (empty($this->pivotFields)) {
            return null;
        }

        return join(
            ', ',
            array_map(function ($field) {
                if (!str_starts_with($field, 'pv.')) {
                    return "pv.$field";
                }
                return $field;
            }, $this->pivotFields)
        );
    }

    /**
     * Build the pivot conditions for the relationship.
     * 
     * @return array
     */
    protected function buildPivotConditions(): array
    {
        return array_map(function ($condition) {
            if (
                is_array($condition) && isset($condition[0]) &&
                !str_starts_with($condition[0], 'pv.')
            ) {
                $condition[0] = "pv.$condition[0]";
            }
            return $condition;
        }, $this->wherePivot);
    }

    /**
     * Add additional fields to be appended to the relationship.
     * 
     * @param array $fields The fields to append.
     * @return self
     */
    public function withPivot(array $fields): self
    {
        $this->pivotFields = [...$this->pivotFields, ...$fields];
        return $this;
    }

    /**
     * Add additional constraints for the pivot table.
     * 
     * @param array|string $column The column name or an array of conditions.
     * @param string|null $operator The operator for the condition (if $column is a string).
     * @param mixed|null $value The value for the condition (if $column is a string).
     * @return self
     */
    public function wherePivot(array|string $column, null|string $operator = null, $value = null, null|string $andOr = null): self
    {
        $this->wherePivot[] = compact('column', 'operator', 'value', 'andOr');
        return $this;
    }

    /**
     * Add an "OR" condition for the pivot table.
     * 
     * @param array|string $column The column name or an array of conditions.
     * @param string|null $operator The operator for the condition (if $column is a string).
     * @param mixed|null $value The value for the condition (if $column is a string).
     * @return self
     */
    public function orWherePivot(array|string $column, null|string $operator = null, $value = null): self
    {
        return $this->wherePivot($column, $operator, $value, 'OR');
    }

    /**
     * Add a "WHERE IN" condition for the pivot table.
     * 
     * @param string $column The column name for the condition.
     * @param array $values The array of values for the "IN" condition.
     * @return self
     */
    public function wherePivotIn(string $column, array $values): self
    {
        $this->wherePivot[] = [[$column => $values]];
        return $this;
    }

    /**
     * Add an "OR WHERE IN" condition for the pivot table.
     * 
     * @param string $column The column name for the condition.
     * @param array $values The array of values for the "IN" condition.
     * @return self
     */
    public function orWherePivotIn(string $column, array $values): self
    {
        $this->wherePivot[] = [[$column => $values], null, null, 'OR'];
        return $this;
    }

    /**
     * Add a "WHERE NULL" condition for the pivot table.
     * 
     * @param string $column The column name for the condition.
     * @return self
     */
    public function wherePivotNull(string $column): self
    {
        $this->wherePivot[] = sprintf('pv.%s IS NULL', $column);
        return $this;
    }

    /**
     * Add a "WHERE NOT NULL" condition for the pivot table.
     * 
     * @param string $column The column name for the condition.
     * @return self
     */
    public function wherePivotNotNull(string $column): self
    {
        $this->wherePivot[] = sprintf('pv.%s IS NOT NULL', $column);
        return $this;
    }
}

/**
 * Map pivot conditions by replacing 'pv.' with the actual pivot table name.
 * 
 * @param array $condition The array of conditions to be mapped.
 * @param string $pivotTable The name of the pivot table to replace 'pv.' with.
 * @return array The mapped conditions with 'pv.' replaced by the pivot table name.
 * @throws \Spark\Database\Exceptions\InvalidOrmException If the condition format is invalid.
 */
function map_pivot_conditions(array $condition, string $pivotTable): array
{
    $mapped = [];

    $replace = fn($value) => str_ireplace('pv.', "$pivotTable.", $value);

    foreach ($condition as $item) {
        if (
            is_array($item) && array_is_list($item) && count($item) >= 3 &&
            isset($item[0]) && is_string($item[0])
        ) {
            $mapped[] = [
                $replace($item[0]),
                $item[1] ?? null,
                $item[2] ?? null,
                $item[3] ?? null,
            ];
        } elseif (
            is_array($item) && isset($item[0]) && is_array($item[0]) &&
            !array_is_list($item[0])
        ) {
            $mapped[] = [
                collect($item[0])->mapWithKeys(
                    fn($value, $key) => [$replace($key) => $value]
                )->all(),
                $item[1] ?? null,
                $item[2] ?? null,
                $item[3] ?? null,
            ];
        } elseif (is_string($item)) {
            $mapped[] = $replace($item);
        } else {
            throw new \Spark\Database\Exceptions\InvalidOrmException("Invalid pivot condition format: " . json_encode($item));
        }
    }
    return $mapped;
}

/**
 * Map pivot fields by replacing 'pv.' with the actual pivot table name.
 * 
 * @param array|string $fields The fields to be mapped, either as an array or a comma-separated string.
 * @param string $pivotTable The name of the pivot table to replace 'pv.' with.
 * @return array|string The mapped fields with 'pv.' replaced by the pivot table name, in the same format as the input.
 */
function map_pivot_fields(array|string|null $fields, string $pivotTable): array|string
{
    if ($fields === null) {
        return ''; // Return an empty string if no fields are defined
    }

    $replace = fn(string $field) => str_ireplace('pv.', "$pivotTable.", $field);

    if (is_array($fields)) {
        return array_map($replace, array_filter($fields));
    }

    return $replace($fields);
}
