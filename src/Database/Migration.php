<?php

namespace Spark\Database;

use PDO;
use PDOStatement;
use Spark\Console\Prompt;
use Spark\Database\Contracts\MigrationContract;
use Spark\Facades\DB;
use Throwable;
use function array_slice;
use function count;
use function in_array;
use function is_object;

/**
 * Class Migration
 *
 * A class for managing database migrations and seeders. Applied migration/seed
 * records are persisted in a database table (created automatically on first
 * use).
 *
 * @package Spark\Database
 */
class Migration implements MigrationContract
{
    /**
     * The PDO connection used to track and run migrations.
     */
    private PDO $pdo;

    /**
     * Creates a new instance of the migration class.
     *
     * @param string|null $migrationsFolder
     *   The path to the folder containing the migration PHP files.
     *   Defaults to "database/migrations" in the root directory.
     *
     * @param string $table
     *   The table used to persist applied migration/seed records.
     *   Defaults to "migrations".
     */
    public function __construct(private ?string $migrationsFolder = null, private string $table = 'migrations')
    {
        $this->migrationsFolder ??= root_dir('database/migrations'); // Default to the root directory

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException('Invalid migration table name.');
        }

        $this->pdo = DB::getPdo();

        if (!in_array($driver = DB::getDriver(), ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new \RuntimeException('Unsupported migration database driver.');
        }

        $quote = $driver === 'mysql' ? '`' : '"';
        $this->table = $quote . $this->table . $quote;
    }

    /**
     * Applies new migrations.
     *
     * Scans the folder for migration files (all PHP files) and executes the
     * up() method on those that haven't been applied yet.
     *
     * @param array $args
     *  An array containing the arguments for the migration.
     * @return void
     */
    public function up(array $args = []): void
    {
        if (!$this->confirmToProceed($args, 'run migrations')) {
            return;
        }

        $this->ensureTableExists();

        $appliedMigrations = $this->getAppliedMigrations();

        $allowSeeds = (isset($args['seed']) && $args['seed']) || (isset($args['s']) && $args['s']); // Allow seeds if specified in args

        // Get all PHP files in the folder.
        $files = glob($this->migrationsFolder . DIRECTORY_SEPARATOR . '*.php') ?: [];

        // Sort files for consistency in the order they are applied.
        sort($files);

        $batch = $this->nextBatchNumber();
        $run = 0;
        $migrationName = null;

        try {
            foreach ($files as $file) {
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

                if ($run === 0) {
                    Prompt::info('Running migrations.');
                }

                $startedAt = microtime(true);
                Prompt::status($migrationName, 'RUNNING');

                // Include the migration file; it should return an instance with up() and down() methods.
                $migration = require $file;

                if (!is_object($migration) || !is_callable([$migration, 'up'])) {
                    throw new \RuntimeException("Migration must expose a public up() method: {$migrationName}");
                }

                $this->runMigration(function () use ($migration, $migrationName, $batch): void {
                    $migration->up();
                    $this->recordMigration(
                        $migrationName,
                        str_starts_with($migrationName, 'seed_') ? 'seed' : 'migration',
                        $batch,
                    );
                });

                Prompt::status($migrationName, 'DONE', microtime(true) - $startedAt);

                $run++;
            }
        } catch (Throwable $e) {
            Prompt::status($migrationName, 'FAIL', microtime(true) - $startedAt);
            Prompt::message("Migration failed: {$migrationName} — {$e->getMessage()}", 'error');
            throw $e;
        }

        if ($run === 0) {
            Prompt::message("Nothing to migrate.", 'info');
        }
    }

    /**
     * Rolls back the last applied migrations.
     *
     * Retrieves the list of applied migrations, most recently applied first,
     * and calls down() on the specified number of them.
     *
     * @param array $args
     *   An array containing the number of steps to rollback.
     *   If 'step' is not provided, it defaults to 1.
     * @return void
     */
    public function down(array $args = []): void
    {
        if (!$this->confirmToProceed($args, 'roll back migrations')) {
            return;
        }

        $this->ensureTableExists();

        $appliedMigrations = $this->getAppliedMigrations();

        if (empty($appliedMigrations)) {
            Prompt::message("Nothing to roll back.", 'warning');
            return;
        }

        $steps = (int) ($args['step'] ?? ($args['_args'][0] ?? 1));

        if (isset($args['all']) && $args['all']) {
            $steps = count($appliedMigrations); // Rollback all applied migrations
        }

        if ($steps < 1) {
            throw new \InvalidArgumentException('Rollback steps must be a positive integer.');
        }

        // Most recently applied migrations are rolled back first.
        $migrationsToRollback = array_slice(array_reverse($appliedMigrations), 0, $steps);

        $run = 0;
        Prompt::info('Rolling back migrations.');

        try {
            foreach ($migrationsToRollback as $migrationName) {
                $startedAt = microtime(true);
                Prompt::status($migrationName, 'RUNNING');

                $file = $this->migrationsFolder . DIRECTORY_SEPARATOR . $migrationName;

                if (!file_exists($file)) {
                    throw new \RuntimeException("Migration file not found: {$migrationName}");
                }

                $migration = require $file;

                if (!is_object($migration) || !is_callable([$migration, 'down'])) {
                    throw new \RuntimeException("Migration must expose a public down() method: {$migrationName}");
                }

                $this->runMigration(function () use ($migration, $migrationName): void {
                    $migration->down();
                    $this->removeMigration($migrationName);
                });

                Prompt::status($migrationName, 'DONE', microtime(true) - $startedAt);

                $run++;
            }
        } catch (Throwable $e) {
            Prompt::status($migrationName, 'FAIL', microtime(true) - $startedAt);
            Prompt::message("Rollback failed: {$e->getMessage()}", 'error');
            throw $e;
        }

        if ($run === 0) {
            Prompt::message("No migrations were rolled back.", 'info');
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
    public function refresh(array $args = []): void
    {
        if (!$this->confirmToProceed($args, 'roll back all migrations and run them again')) {
            return;
        }

        $args['force'] = true; // The entire operation has already been confirmed.
        $args['all'] = true; // Default to rolling back all applied migrations

        // Rollback all applied migrations
        $this->down($args);

        Prompt::newline();

        $this->up($args);
    }

    /** Require explicit approval before changing a database with debug disabled. */
    private function confirmToProceed(array $args, string $action): bool
    {
        if (config('app.debug', false) || filter_var($args['force'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        Prompt::alert("Application debug mode is disabled. You are about to {$action}; this may change or delete data.");

        if (Prompt::confirm('Are you sure you want to continue?', false)) {
            return true;
        }

        Prompt::warning('Migration command cancelled.');

        return false;
    }

    private function runMigration(callable $callback): void
    {
        if (DB::isMySQL()) {
            $callback();
        } else {
            DB::transaction($callback);
        }
    }

    /**
     * Ensures the migrations ledger table exists, creating it if necessary.
     *
     * @return void
     */
    private function ensureTableExists(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'sqlite' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                type TEXT NOT NULL DEFAULT 'migration',
                batch INTEGER NOT NULL,
                applied_at INTEGER NOT NULL
            )",
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                type VARCHAR(10) NOT NULL DEFAULT 'migration',
                batch INTEGER NOT NULL,
                applied_at INTEGER NOT NULL
            )",
            default => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                type VARCHAR(10) NOT NULL DEFAULT 'migration',
                batch INT UNSIGNED NOT NULL,
                applied_at INT UNSIGNED NOT NULL,
                UNIQUE (migration)
            )",
        };

        $this->pdo->exec($sql);
    }

    /**
     * Returns the list of applied migration/seed filenames, oldest first.
     *
     * @return array<int, string>
     */
    private function getAppliedMigrations(): array
    {
        $stmt = $this->run("SELECT migration FROM {$this->table} ORDER BY id ASC");

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Records an applied migration/seed in the ledger table.
     */
    private function recordMigration(string $migration, string $type, int $batch): void
    {
        $this->run(
            "INSERT INTO {$this->table} (migration, type, batch, applied_at) VALUES (?, ?, ?, ?)",
            [$migration, $type, $batch, time()]
        );
    }

    /**
     * Removes a migration/seed record from the ledger table.
     */
    private function removeMigration(string $migration): void
    {
        $this->run("DELETE FROM {$this->table} WHERE migration = ?", [$migration]);
    }

    /**
     * Returns the next batch number to tag newly applied migrations with.
     */
    private function nextBatchNumber(): int
    {
        $max = $this->run("SELECT MAX(batch) FROM {$this->table}")->fetchColumn();

        return $max !== false && $max !== null ? ((int) $max + 1) : 1;
    }

    /**
     * Prepare, bind (ints as PARAM_INT) and execute.
     */
    private function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        foreach (array_values($params) as $i => $value) {
            $stmt->bindValue($i + 1, $value, \is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();

        return $stmt;
    }
}