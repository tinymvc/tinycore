<?php

namespace Spark\Database\Schema\Contracts;

/**
 * Interface for a database column.
 *
 * @package Spark\Database\Schema\Contracts
 */
interface ColumnContract
{
    /**
     * Make the column nullable.
     *
     * @return self
     */
    public function nullable(): self;

    /**
     * Set a default value for the column.
     *
     * @param mixed $value The default value to set.
     * @return self
     */
    public function default($value): self;

    /**
     * Mark the column as unsigned.
     *
     * @return self
     */
    public function unsigned(): self;

    /**
     * Enable auto-increment for the column.
     *
     * @return self
     */
    public function autoIncrement(): self;

    /**
     * Generate the SQL representation of the column.
     *
     * @return string
     */
    public function toSql(): string;
}
