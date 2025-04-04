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
     * @param string $table
     * @return static
     */
    public function constrained(string $table = null): self;

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
}
