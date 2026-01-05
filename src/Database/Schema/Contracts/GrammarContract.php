<?php

namespace Spark\Database\Schema\Contracts;

use Spark\Database\Schema\ForeignKeyConstraint;

/**
 * Interface GrammarContract
 *
 * This interface defines the methods that must be implemented by a grammar instance.
 * A grammar instance is used to generate SQL code for a given database connection.
 *
 * @package Spark\Database\Schema\Contracts
 */
interface GrammarContract
{
    /**
     * Compile an index.
     *
     * This method is used to generate the SQL code to create an index on a table.
     *
     * @param string $table The name of the table.
     * @param array $index The index definition.
     * @return string The SQL code to create the index.
     */
    public function compileIndex(string $table, array $index): string;

    /**
     * Compile a foreign key constraint.
     *
     * This method is used to generate the SQL code to create a foreign key constraint on a table.
     *
     * @param ForeignKeyConstraint $foreignKey The foreign key constraint.
     * @return string The SQL code to create the foreign key constraint.
     */
    public function compileForeignKey(ForeignKeyConstraint $foreignKey): string;

    /**
     * Map a column modifier to a database-specific clause.
     *
     * This method is used to map a column modifier to a database-specific clause so that it can be used in an SQL query.
     *
     * @param string $modifier The modifier name.
     * @param mixed $value The value associated with the modifier.
     * @return string The database-specific clause.
     */
    public function mapModifier(string $modifier, $value = null): string;

    /**
     * Map a column type to a database-specific type.
     *
     * This method is used to map a column type to a database-specific type so that it can be used in an SQL query.
     *
     * @param string $type The column type.
     * @param array $parameters Additional parameters for the type.
     * @return string The database-specific type.
     */
    public function mapColumnType(string $type, array $parameters): string;
}
