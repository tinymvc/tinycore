<?php

namespace Spark\Database\Schema\Contracts;

use Spark\Database\Schema\Column;
use Spark\Database\Schema\ForeignKeyConstraint;

/**
 * Interface for a database table blueprint.
 * 
 * @package Spark\Database\Schema\Contracts
 */
interface BlueprintContract
{
    /**
     * Add an 'id' column to the blueprint.
     *
     * @param string $name The name of the column.
     *
     * @return Column
     */
    public function id(string $name = 'id'): Column;

    /**
     * Add an auto-incrementing integer primary key.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function increments(string $name): Column;

    /**
     * Add a 'string' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $length The length of the string.
     *
     * @return Column
     */
    public function string(string $name, int $length = 255): Column;

    /**
     * Add an 'integer' column to the blueprint.
     *
     * @param string $name The name of the column.
     *
     * @return Column
     */
    public function integer(string $name): Column;

    /**
     * Add an unsigned integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function unsignedInteger(string $name): Column;

    /**
     * Add a small integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function smallInteger(string $name): Column;

    /**
     * Add a medium integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function mediumInteger(string $name): Column;

    /**
     * Add a 'text' column to the blueprint.
     *
     * @param string $name The name of the column.
     *
     * @return Column
     */
    public function text(string $name): Column;

    /**
     * Add 'created_at' and 'updated_at' timestamp columns to the blueprint.
     *
     * @return void
     */
    public function timestamps(): void;

    /**
     * Add a 'timestamp' column to the blueprint.
     *
     * @param string $name The name of the column.
     *
     * @return Column
     */
    public function timestamp(string $name, int $precision = 0): Column;

    /**
     * Add a 'boolean' column to the blueprint.
     *
     * @param string $name The name of the column.
     *
     * @return Column
     */
    public function boolean(string $name): Column;

    /**
     * Add a 'foreignId' column to the blueprint.
     *
     * @param string $name The name of the column.
     *
     * @return ForeignKeyConstraint
     */
    public function foreignId(string $name, bool $nullable = false): ForeignKeyConstraint;

    /**
     * Add a 'foreign' column to the blueprint.
     *
     * @param array|string $columns The column(s) to constrain.
     * @param string|null $name The name of the table the column references.
     *
     * @return ForeignKeyConstraint
     */
    public function foreign(array|string $columns, ?string $name = null): ForeignKeyConstraint;

    /**
     * Add a constrained 'foreign' column to the blueprint.
     *
     * @param string $column The name of the column.
     * @param string|null $table The name of the table the column references.
     *
     * @return ForeignKeyConstraint
     */
    public function constrained(string $column, ?string $table = null): ForeignKeyConstraint;

    /**
     * Add a 'decimal' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $precision The precision of the column.
     * @param int $scale The scale of the column.
     *
     * @return Column
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): Column;

    /**
     * Add a 'bigIncrements' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function bigIncrements(string $name = 'id'): Column;

    /**
     * Add a 'bigInteger' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function bigInteger(string $name): Column;

    /**
     * Add an unsigned big integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function unsignedBigInteger(string $name): Column;

    /**
     * Add a 'double' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param null|int $precision The precision of the column.
     * @param null|int $scale The scale of the column.
     *
     * @return Column
     */
    public function double(string $name, ?int $precision = null, ?int $scale = null): Column;

    /**
     * Add a 'float' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param null|int $precision The precision of the column.
     * @param null|int $scale The scale of the column.
     *
     * @return Column
     */
    public function float(string $name, ?int $precision = null, ?int $scale = null): Column;

    /**
     * Add an 'enum' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param array $allowed The allowed values for the column.
     *
     * @return Column
     */
    public function enum(string $name, array $allowed): Column;

    /**
     * Add a 'char' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $length The length of the column.
     *
     * @return Column
     */
    public function char(string $name, int $length = 255): Column;

    /**
     * Add a 'longText' column to the blueprint.
     *
     * @param string $name The name of the column.
     *
     * @return Column
     */
    public function longText(string $name): Column;

    /**
     * Add a 'date' column to the blueprint.
     *
     * @param string $name The name of the column.
     *
     * @return Column
     */
    public function date(string $name): Column;

    /**
     * Add a 'dateTime' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $precision The precision of the column.
     *
     * @return Column
     */
    public function dateTime(string $name, int $precision = 0): Column;

    /**
     * Add a 'primary' index to the blueprint.
     *
     * @param array $columns The columns to be indexed.
     *
     * @return void
     */
    public function primary($columns, ?string $name = null): void;

    /**
     * Add a 'unique' index to the blueprint.
     *
     * @param string|array $columns The columns to be indexed.
     *
     * @return void
     */
    public function unique(string|array $columns, ?string $name = null): void;

    /**
     * Add an 'index' to the blueprint.
     *
     * @param string|array $columns The columns to be indexed.
     *
     * @return void
     */
    public function index(string|array $columns, ?string $name = null): void;

    /**
     * Add a full text index to the blueprint.
     *
     * @param string|array $columns The columns to be indexed.
     * @return void
     */
    public function fullText(string|array $columns, ?string $name = null): void;

    /**
     * Add a spatial index to the blueprint.
     *
     * @param string|array $columns The columns to be indexed.
     * @param string|null $name The index name.
     * @return void
     */
    public function spatialIndex(string|array $columns, ?string $name = null): void;

    /**
     * Drop an index from the blueprint.
     *
     * @param string|array $index The index name or columns.
     * @return self
     */
    public function dropIndex(string|array $index): self;

    /**
     * Drop a unique index from the blueprint.
     *
     * @param string|array $index The index name or columns.
     * @return self
     */
    public function dropUnique(string|array $index): self;

    /**
     * Drop a foreign key from the blueprint.
     *
     * @param string|array $index The foreign key name or columns.
     * @return self
     */
    public function dropForeign(string|array $index): self;

    /**
     * Set the MySQL storage engine for the table.
     *
     * @param string $engine The engine name.
     * @return self
     */
    public function engine(string $engine): self;

    /**
     * Compile the blueprint into a SQL statement.
     *
     * @return string
     */
    public function compileCreate(): string;

    /**
     * Compile the blueprint into SQL statements.
     *
     * @return array
     */
    public function compileCreateStatements(): array;

    /**
     * Compile the blueprint into an ALTER TABLE statement.
     *
     * @return string
     */
    public function compileAlter(): string;

    /**
     * Compile the blueprint into ALTER TABLE SQL statements.
     *
     * @return array
     */
    public function compileAlterStatements(): array;
}
