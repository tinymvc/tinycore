<?php

namespace Spark\Database;

use Spark\Console\Prompt;
use Spark\Contracts\Database\MigrationContract;
use Spark\Database\Exceptions\InvalidMigrationFile;

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
     * @param Prompt $prompt
     *   The prompt service.
     *
     * @param string|null $migrationsFolder
     *   The path to the folder containing the migration PHP files.
     *   Defaults to "database/migrations" in the root directory.
     *
     * @param string|null $migrationFile
     *   The path to the JSON file containing the list of applied migrations.
     *   Defaults to "database/migrations.json" in the root directory.
     */

    public function __construct(private Prompt $prompt, private ?string $migrationsFolder = null, private ?string $migrationFile = null)
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
     * @return void
     */
    public function up(): void
    {
        $appliedMigrations = $this->getAppliedMigrations();

        // Get all PHP files in the folder (excluding migrations.json)
        $files = glob($this->migrationsFolder . DIRECTORY_SEPARATOR . '*.php');

        // Sort files for consistency in the order they are applied.
        sort($files);

        foreach ($files as $file) {
            if (basename($file) === 'migrations.json') {
                continue;
            }

            $migrationName = basename($file);

            // Skip if this migration has already been applied.
            if (in_array($migrationName, $appliedMigrations)) {
                continue;
            }

            // Include the migration file; it should return an instance with up() and down() methods.
            $migration = require $file;

            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();

                $this->prompt->message("Applied migration: {$migrationName}", 'success');

                $appliedMigrations[] = $migrationName;
            }
        }

        // Save the list of applied migrations
        $this->saveAppliedMigrations($appliedMigrations);
    }

    /**
     * Rolls back the last $steps applied migrations.
     *
     * @param int $steps
     *   The number of migrations to rollback. Defaults to 1.
     *
     * @return void
     */
    public function down(int $steps = 1): void
    {
        $appliedMigrations = $this->getAppliedMigrations();

        if (empty($appliedMigrations)) {
            $this->prompt->message("No migrations to rollback.", 'warning');
            return;
        }

        // Determine the migrations to rollback: the last $steps applied migrations.
        $migrationsToRollback = array_slice($appliedMigrations, -$steps);

        foreach ($migrationsToRollback as $migrationName) {
            $file = $this->migrationsFolder . DIRECTORY_SEPARATOR . $migrationName;

            if (!file_exists($file)) {
                $this->prompt->message("Migration file not found: {$migrationName}", 'warning');
                continue;
            }

            $migration = require $file;

            if (is_object($migration) && method_exists($migration, 'down')) {
                $migration->down();

                $this->prompt->message("Rolled back migration: {$migrationName}", 'success');
            }
        }

        // Update the migration record by removing the rolled-back migrations.
        $remainingMigrations = array_slice($appliedMigrations, 0, -$steps);

        // Save the list of applied migrations
        $this->saveAppliedMigrations($remainingMigrations);
    }

    /**
     * Rolls back all applied migrations and then applies all migrations again.
     *
     * This is useful for quickly setting up a database in a development environment.
     *
     * @return void
     */
    public function refresh(): void
    {
        // Rollback all applied migrations
        $this->down(count($this->getAppliedMigrations()));

        $this->prompt->newline();

        $this->up();
    }
}