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
    public function nullable(bool $value = true): self;

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
     * Get the column name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Mark the column as unique.
     *
     * @return self
     */
    public function unique(): self;

    /**
     * Mark the column as required (NOT NULL).
     *
     * @param bool $value
     * @return self
     */
    public function required(bool $value = true): self;

    /**
     * Set the default value to CURRENT_TIMESTAMP.
     *
     * @return self
     */
    public function useCurrent();

    /**
     * Set ON UPDATE CURRENT_TIMESTAMP.
     *
     * @return self
     */
    public function useCurrentOnUpdate();

    /**
     * Add an index for the column.
     *
     * @param string|null $name
     * @return self
     */
    public function index(?string $name = null): self;

    /**
     * Add a primary key index for the column.
     *
     * @param string|null $name
     * @return self
     */
    public function primary(?string $name = null): self;

    /**
     * Place the column "after" another column.
     *
     * @param string $column
     * @return self
     */
    public function after(string $column);

    /**
     * Add a comment to the column.
     *
     * @param string $comment
     * @return self
     */
    public function comment(string $comment);

    /**
     * Generate the SQL representation of the column.
     *
     * @return string
     */
    public function toSql(): string;
}
