<?php

namespace Spark\Contracts\Database;

/**
 * Interface for database migration contracts.
 *
 * This interface defines the methods that a database migration
 * must implement. The methods are used to migrate the database
 * up and down, and to refresh the database.
 */
interface MigrationContract
{
    /**
     * Migrate the database up.
     *
     * This method is used to migrate the database up. It should
     * contain the code to add the tables, fields, and indexes to
     * the database.
     *
     * @return void
     */
    public function up(): void;

    /**
     * Migrate the database down.
     *
     * This method is used to migrate the database down. It should
     * contain the code to drop the tables, fields, and indexes from
     * the database.
     *
     * @param int $steps The number of steps to migrate down. Defaults to 1.
     *
     * @return void
     */
    public function down(int $steps = 1): void;

    /**
     * Refresh the database.
     *
     * This method is used to refresh the database. It should
     * contain the code to drop the database and then migrate
     * it up.
     *
     * @return void
     */
    public function refresh(): void;
}
