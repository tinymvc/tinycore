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
     * @param array $args
     * @return void
     */
    public function up(array $args): void;

    /**
     * Migrate the database down.
     *
     * This method is used to migrate the database down. It should
     * contain the code to drop the tables, fields, and indexes from
     * the database.
     * 
     * @param array $args
     * @return void
     */
    public function down(array $args): void;

    /**
     * Refresh the database.
     *
     * This method is used to refresh the database. It should
     * contain the code to drop the database and then migrate
     * it up.
     *
     * @param array $args
     * @return void
     */
    public function refresh(array $args): void;
}
