<?php

namespace Spark\Database\Schema;

use Spark\Database\Schema\Contracts\ForeignKeyConstraintContract;
use Spark\Support\Str;

/**
 * Class ForeignKeyConstraint
 *
 * A class to represent foreign key constraints used in Schema migrations.
 *
 * @package Spark\Database\Schema
 */
class ForeignKeyConstraint implements ForeignKeyConstraintContract
{
    /**
     * The columns that will be used to form the foreign key.
     *
     * @var array
     */
    public array $columns;

    /**
     * The columns that the foreign key references.
     *
     * @var array
     */
    public array $references;

    /**
     * The table that the foreign key references.
     *
     * @var string
     */
    public string $onTable;

    /**
     * The action to take when the referenced record is deleted.
     *
     * @var string
     */
    public string $onDelete;

    /**
     * The action to take when the referenced record is updated.
     *
     * @var string
     */
    public string $onUpdate;

    /**
     * Create a new foreign key constraint.
     *
     * @param  string|array  $columns
     * @return void
     */
    public function __construct(string|array $columns)
    {
        $this->columns = (array) $columns;

        if (Schema::getGrammar()->isSQLite()) {
            Schema::execute('PRAGMA foreign_keys = ON;');
        }
    }

    /**
     * Create a foreign key constraint on the specified table.
     *
     * @param  string|null  $table
     * @return $this
     */
    public function constrained(string $table = null): self
    {
        // Take to word before _id
        $table ??= Str::lower(
            Str::plural(Str::beforeLast($this->columns[0], '_id'))
        );
        return $this->references('id')->on($table);
    }

    /**
     * Set the columns that the foreign key references.
     *
     * @param  string|array  $columns
     * @return $this
     */
    public function references(string|array $columns): self
    {
        $this->references = (array) $columns;
        return $this;
    }

    /**
     * Set the table that the foreign key references.
     *
     * @param  string  $table
     * @return $this
     */
    public function on(string $table): self
    {
        $this->onTable = $table;
        return $this;
    }

    /**
     * Set the action to take when the referenced record is deleted.
     *
     * @param  string  $action
     * @return $this
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    /**
     * Set the action to take when the referenced record is updated.
     *
     * @param  string  $action
     * @return $this
     */
    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    /**
     * Set the foreign key to cascade when the referenced record is deleted.
     *
     * @return $this
     */
    public function cascadeOnDelete()
    {
        return $this->onDelete('cascade');
    }

    /**
     * Set the foreign key to cascade when the referenced record is updated.
     *
     * @return $this
     */
    public function cascadeOnUpdate()
    {
        return $this->onUpdate('cascade');
    }

    /**
     * Set the foreign key to set to null when the referenced record is deleted.
     *
     * @return $this
     */
    public function setNullOnDelete()
    {
        return $this->onDelete('set null');
    }

    /**
     * Set the foreign key to set to null when the referenced record is updated.
     *
     * @return $this
     */
    public function setNullOnUpdate()
    {
        return $this->onUpdate('set null');
    }

    /**
     * Set the foreign key to set to its default value when the referenced record is deleted.
     *
     * @return $this
     */
    public function setDefaultOnDelete()
    {
        return $this->onDelete('set default');
    }

    /**
     * Set the foreign key to set to its default value when the referenced record is updated.
     *
     * @return $this
     */
    public function setDefaultOnUpdate()
    {
        return $this->onUpdate('set default');
    }

    /**
     * Set the foreign key to do nothing when the referenced record is deleted.
     *
     * @return $this
     */
    public function noActionOnDelete()
    {
        return $this->onDelete('no action');
    }

    /**
     * Set the foreign key to do nothing when the referenced record is updated.
     *
     * @return $this
     */
    public function noActionOnUpdate()
    {
        return $this->onUpdate('no action');
    }

    /**
     * Set the foreign key to restrict when the referenced record is deleted.
     *
     * @return $this
     */
    public function restrictOnDelete()
    {
        return $this->onDelete('restrict');
    }

    /**
     * Set the foreign key to restrict when the referenced record is updated.
     *
     * @return $this
     */
    public function restrictOnUpdate()
    {
        return $this->onUpdate('restrict');
    }
}
