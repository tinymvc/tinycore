<?php

namespace Spark\Database\Schema\Contracts;

/**
 * Foreign key constraint interface.
 * 
 * @package Spark\Database\Schema\Contracts
 */
interface ForeignKeyConstraintContract
{
    /**
     * Set the table name that is being constrained.
     *
     * @param null|string $table
     * @return static
     */
    public function constrained(?string $table = null): self;

    /**
     * Set the constraint name.
     *
     * @param string $name
     * @return static
     */
    public function name(string $name): self;

    /**
     * Set the columns that the foreign key references.
     *
     * @param string|array $columns
     * @return static
     */
    public function references(string|array $columns): self;

    /**
     * Set the table that the foreign key references.
     *
     * @param string $table
     * @return static
     */
    public function on(string $table): self;

    /**
     * Set the ON DELETE action.
     *
     * @param string $action
     * @return static
     */
    public function onDelete(string $action): self;

    /**
     * Set the ON UPDATE action.
     *
     * @param string $action
     * @return static
     */
    public function onUpdate(string $action): self;

    /**
     * Set ON DELETE to CASCADE.
     *
     * @return self
     */
    public function cascadeOnDelete(): self;

    /**
     * Set ON UPDATE to CASCADE.
     *
     * @return self
     */
    public function cascadeOnUpdate(): self;

    /**
     * Set ON DELETE to SET NULL.
     *
     * @return self
     */
    public function setNullOnDelete(): self;

    /**
     * Set ON UPDATE to SET NULL.
     *
     * @return self
     */
    public function setNullOnUpdate(): self;

    /**
     * Set ON DELETE to RESTRICT.
     *
     * @return self
     */
    public function restrictOnDelete(): self;

    /**
     * Set ON UPDATE to RESTRICT.
     *
     * @return self
     */
    public function restrictOnUpdate(): self;

    /**
     * Set ON DELETE to NO ACTION.
     *
     * @return self
     */
    public function noActionOnDelete(): self;

    /**
     * Set ON UPDATE to NO ACTION.
     *
     * @return self
     */
    public function noActionOnUpdate(): self;

    /**
     * Set the column to nullable.
     *
     * @param bool $nullable
     * @return self
     */
    public function nullable(bool $nullable = true): self;
}
