<?php

namespace Spark\Database\Concerns;

use Spark\Database\DB;

/**
 * Trait InteractsWithSoftDeletes
 *
 * This trait provides functionality for models that support soft deletes.
 * It allows you to check if a model uses soft deletes, build WHERE clauses
 * for soft delete queries, and restore soft deleted model instances.
 */
trait InteractsWithSoftDeletes
{
    /** Indicates whether the model should use soft deletes. */
    protected const USE_SOFT_DELETES = false;

    /** The name of the column used for soft deletes. */
    protected const SOFT_DELETE_COLUMN = 'deleted_at';

    /**
     * Build the WHERE clause for soft delete queries.
     *
     * @param string $where The existing WHERE clause.
     * @param bool $not Whether to include the NOT NULL condition. true=deleted, false=not deleted
     * @param string|null $column Quoted, qualified SQL column supplied by the query builder.
     * @return string The modified WHERE clause.
     */
    public function buildSoftDeleteWhereClause(string $where, bool $not = true, ?string $column = null): string
    {
        if (!$this->usesSoftDeletes()) {
            return $where; // No modification needed if soft deletes are not used
        }

        $column ??= $this->getSoftDeleteColumn();
        $boolean = $not ? 'IS NOT NULL' : 'IS NULL';

        $where = trim($where ?: '');

        return empty($where) ? " WHERE $column $boolean "
            : " WHERE $column $boolean AND (" . preg_replace('/^WHERE\s+/i', '', $where, 1) . ") ";
    }

    /**
     * Check if the model uses soft deletes.
     *
     * @return bool True if the model uses soft deletes, false otherwise.
     */
    public function usesSoftDeletes(): bool
    {
        return (bool) static::USE_SOFT_DELETES === true;
    }

    /**
     * Get the name of the soft delete column.
     *
     * @return string The name of the soft delete column.
     */
    public function getSoftDeleteColumn(): string
    {
        return static::SOFT_DELETE_COLUMN ?? 'deleted_at';
    }

    /**
     * Check if the model instance is trashed (soft deleted).
     *
     * @return bool True if the model instance is trashed, false otherwise.
     */
    public function trashed(): bool
    {
        if (!$this->usesSoftDeletes()) {
            return false;
        }

        $column = $this->getSoftDeleteColumn();

        return !empty($this->attributes[$column]);
    }

    /**
     * Restore a soft deleted model instance.
     *
     * @return bool True if the model instance was restored, false otherwise.
     */
    public function restore(): bool
    {
        if (!$this->usesSoftDeletes()) {
            return false;
        }

        $column = $this->getSoftDeleteColumn();

        if ($this->trashed() && $this->hasPrimaryValue()) {
            $this->attributes[$column] = null;

            return (bool) DB::table($this->getTable())
                ->where($this->getPrimaryKey(), $this->primaryValue())
                ->update([$column => null]) > 0;
        }

        return false;
    }
}
