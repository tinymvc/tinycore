<?php

namespace Spark\Database;

use Spark\Console\Prompt;
use Spark\Database\Contracts\MigrationContract;
use Spark\Database\Exceptions\InvalidMigrationFile;
use Throwable;

/**
 * Class Migration
 *
 * A class for managing database migrations. It provides methods for
 * listing, applying, and rolling back migrations.
 *
 * @package Spark\Database
 */
class Migration implements MigrationContract
{
    /**
     * Creates a new instance of the migration class.
     *
     * @param string|null $migrationsFolder
     *   The path to the folder containing the migration PHP files.
     *   Defaults to "database/migrations" in the root directory.
     *
     * @param string|null $migrationFile
     *   The path to the JSON file containing the list of applied migrations.
     *   Defaults to "database/migrations.json" in the root directory.
     */

    public function __construct(private ?string $migrationsFolder = null, private ?string $migrationFile = null)
    {
        $this->migrationsFolder ??= root_dir('database/migrations'); // Default to the root directory

        // The JSON file that stores migration records.
        $this->migrationFile ??= root_dir('database/migrations.json');

        // Check if the migration file exists and is writable
        if (!file_exists($this->migrationFile) && !touch($this->migrationFile)) {
            throw new InvalidMigrationFile(sprintf('The migration file (%s) does not exist.', $this->migrationFile));
        } elseif (!is_writable($this->migrationFile) && !chmod($this->migrationFile, 0666)) {
            throw new InvalidMigrationFile(sprintf('The migration file (%s) is not writable.', $this->migrationFile));
        }
    }

    /**
     * Returns the list of applied migrations from the JSON record file.
     *
     * @return array
     *   The list of applied migrations as an array of migration filenames.
     */
    private function getAppliedMigrations(): array
    {
        $json = file_get_contents($this->migrationFile);
        $data = (array) json_decode($json, true);

        return $data['migrations'] ?? [];
    }

    /**
     * Saves the list of applied migrations to the JSON record file.
     *
     * This method updates the migration record file with the given array
     * of migration filenames, ensuring that all applied migrations are
     * stored in a structured JSON format.
     *
     * @param array $migrations
     *   An array of migration filenames that have been applied.
     *
     * @return void
     */
    private function saveAppliedMigrations(array $migrations): void
    {
        $data = ['migrations' => $migrations];

        file_put_contents($this->migrationFile, json_encode($data));
    }

    /**
     * Applies new migrations.
     *
     * Scans the folder for migration files (all PHP files except the JSON record)
     * and executes the up() method on those that haven’t been applied yet.
     * 
     * @param array $args
     *  An array containing the arguments for the migration.
     * @return void
     */
    public function up(array $args): void
    {
        $appliedMigrations = $this->getAppliedMigrations();

        $allowSeeds = (isset($args['seed']) && $args['seed']) || (isset($args['s']) && $args['s']); // Allow seeds if specified in args

        // Get all PHP files in the folder (excluding migrations.json)
        $files = glob($this->migrationsFolder . DIRECTORY_SEPARATOR . '*.php');

        // Sort files for consistency in the order they are applied.
        sort($files);

        try {
            foreach ($files as $file) {
                if (basename($file) === 'migrations.json') {
                    continue;
                }

                $migrationName = basename($file);

                if (
                    !str_starts_with($migrationName, 'migration_')
                    && !($allowSeeds && str_starts_with($migrationName, 'seed_'))
                ) {
                    // not a migration, and not an allowed seed → skip it
                    continue;
                }

                // Skip if this migration has already been applied.
                if (in_array($migrationName, $appliedMigrations)) {
                    continue;
                }

                // Include the migration file; it should return an instance with up() and down() methods.
                $migration = require $file;

                if (is_object($migration) && method_exists($migration, 'up')) {
                    $migration->up();

                    Prompt::message("Applied migration: {$migrationName}", 'success');

                    $appliedMigrations[] = $migrationName;
                }
            }
            // Save the list of applied migrations
            $this->saveAppliedMigrations($appliedMigrations);
        } catch (Throwable $e) {
            $this->saveAppliedMigrations($appliedMigrations); // Save the list of applied migrations
            throw $e;
        }
    }

    /**
     * Rolls back the last applied migrations.
     *
     * This method rolls back the last $steps applied migrations.
     * It retrieves the list of applied migrations, reverses the order,
     * and applies the down() method on the specified number of migrations.
     * 
     * @param array $args
     *   An array containing the number of steps to rollback.
     *   If 'step' is not provided, it defaults to 1.
     * @return void
     */
    public function down(array $args): void
    {
        $steps = $args['step'] ?? ($args['_args'][0] ?? 1);

        $appliedMigrations = $this->getAppliedMigrations();

        if (isset($args['all']) && $args['all']) {
            $steps = count($appliedMigrations); // Rollback all applied migrations
        }

        if (empty($appliedMigrations)) {
            Prompt::message("No migrations to rollback.", 'warning');
            return;
        }

        // Reverse the applied migrations, so they are applied in the correct order
        $remainingMigrations = array_reverse($appliedMigrations);

        // Determine the migrations to rollback: the last $steps applied migrations.
        $migrationsToRollback = array_slice($remainingMigrations, 0, $steps);

        try {
            foreach ($migrationsToRollback as $index => $migrationName) {
                $file = $this->migrationsFolder . DIRECTORY_SEPARATOR . $migrationName;

                if (!file_exists($file)) {
                    Prompt::message("Migration file not found: {$migrationName}", 'warning');
                    continue;
                }

                $migration = require $file;

                if (is_object($migration) && method_exists($migration, 'down')) {
                    $migration->down();

                    Prompt::message("Rolled back migration: {$migrationName}", 'success');

                    // Remove the rolled back migration from the list
                    $remainingMigrations = array_slice($remainingMigrations, $index + 1);
                }
            }
            // Save the list of applied migrations
            $remainingMigrations = array_reverse($remainingMigrations);
            $this->saveAppliedMigrations($remainingMigrations);
        } catch (Throwable $e) {
            $remainingMigrations = array_reverse($remainingMigrations);
            $this->saveAppliedMigrations($remainingMigrations); // Save the list of applied migrations
            throw $e;
        }
    }

    /**
     * Rolls back all applied migrations and then applies all migrations again.
     *
     * This is useful for quickly setting up a database in a development environment.
     * 
     * @param array $args
     *  An array containing the number of steps to rollback.
     * @return void
     */
    public function refresh(array $args): void
    {
        $args['all'] = true; // Default to rolling back all applied migrations

        // Rollback all applied migrations
        $this->down($args);

        Prompt::newline();

        $this->up($args);
    }
}