<?php

namespace Spark\Database;

use PDO;
use PDOStatement;
use Spark\Console\Prompt;
use Spark\Database\Contracts\MigrationContract;
use Spark\Database\Schema\Schema;
use Spark\Facades\DB;
use Throwable;
use function array_slice;
use function in_array;
use function is_object;
use function is_string;

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
     * The unquoted/unprefixed migration ledger table name.
     */
    private string $rawTable;

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

        $this->rawTable = $this->table;
        $this->pdo = DB::getPdo();

        if (!in_array($driver = DB::getDriver(), DB::SUPPORTED_DRIVERS, true)) {
            throw new \RuntimeException('Unsupported migration database driver.');
        }

        $this->table = DB::wrapTable($this->table);
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
     * Rolls back applied migrations.
     *
     * By default, rolls back all migrations in the latest applied batch, most
     * recently applied first. Supports --step=N, --batch=N, and --all.
     *
     * @param array $args
     *   Rollback options ('step', 'batch', 'all', 'force').
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

        $migrationsToRollback = $this->resolveMigrationsToRollback($args, $appliedMigrations);

        if ($migrationsToRollback === []) {
            Prompt::message("Nothing to roll back.", 'warning');
            return;
        }

        $run = 0;
        $migrationName = null;
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
            Prompt::status((string) $migrationName, 'FAIL', microtime(true) - $startedAt);
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

    /**
     * Show the status of each migration and seeder file without modifying the database.
     *
     * @param array $args Supports --pending and --ran filters.
     * @return void
     */
    public function status(array $args = []): void
    {
        $applied = [];
        if (Schema::hasTable($this->rawTable)) {
            $rows = $this->run("SELECT migration, type, batch, applied_at FROM {$this->table} ORDER BY id ASC")
                ->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $applied[$row['migration']] = $row;
            }
        }

        $files = glob($this->migrationsFolder . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $onlyPending = !empty($args['pending']);
        $onlyRan = !empty($args['ran']);

        $tableRows = [];
        $seen = [];
        $ranCount = 0;
        $pendingCount = 0;
        $missingCount = 0;

        foreach ($files as $file) {
            $name = basename($file);
            if (!str_starts_with($name, 'migration_') && !str_starts_with($name, 'seed_')) {
                continue;
            }

            $seen[$name] = true;
            $type = str_starts_with($name, 'seed_') ? 'seed' : 'migration';

            if (isset($applied[$name])) {
                $ranCount++;
                if ($onlyPending && !$onlyRan) {
                    continue;
                }

                $record = $applied[$name];
                $tableRows[] = [
                    $name,
                    (string) ($record['type'] ?? $type),
                    'Ran',
                    (string) $record['batch'],
                    date('Y-m-d H:i:s', (int) $record['applied_at']),
                ];
            } else {
                $pendingCount++;
                if ($onlyRan && !$onlyPending) {
                    continue;
                }

                $tableRows[] = [$name, $type, 'Pending', '-', '-'];
            }
        }

        foreach ($applied as $name => $record) {
            if (isset($seen[$name])) {
                continue;
            }

            $missingCount++;
            if ($onlyPending && !$onlyRan) {
                continue;
            }

            $tableRows[] = [
                $name,
                (string) ($record['type'] ?? 'migration'),
                'Missing',
                (string) $record['batch'],
                date('Y-m-d H:i:s', (int) $record['applied_at']),
            ];
        }

        if ($tableRows === []) {
            Prompt::info(
                $ranCount + $pendingCount + $missingCount === 0
                ? 'No migrations found.'
                : 'No migrations match the selected filter.'
            );
            return;
        }

        Prompt::table(['Migration', 'Type', 'Status', 'Batch', 'Applied at'], $tableRows);
        $summary = "  Showing {$ranCount} ran, {$pendingCount} pending";
        if ($missingCount > 0) {
            $summary .= ", {$missingCount} missing";
        }
        Prompt::comment($summary . '.');
    }

    /**
     * Run standalone database seeders (seed_*.php) without tracking in the migration ledger.
     *
     * @param array $args Supports positional target, --class=name, --only=name, and --force.
     * @return void
     */
    public function seed(array $args = []): void
    {
        if (!$this->confirmToProceed($args, 'run database seeders')) {
            return;
        }

        $files = glob($this->migrationsFolder . DIRECTORY_SEPARATOR . 'seed_*.php') ?: [];
        sort($files);

        $target = $args['class'] ?? $args['only'] ?? $args['_args'][0] ?? null;
        if (is_string($target)) {
            $target = trim($target);
        } else {
            $target = null;
        }

        if ($target !== null && $target !== '') {
            $needle = strtolower(str_ends_with($target, '.php') ? substr($target, 0, -4) : $target);
            $exact = array_values(array_filter(
                $files,
                static fn(string $file): bool => strtolower(basename($file, '.php')) === $needle
            ));

            $files = $exact !== []
                ? $exact
                : array_values(array_filter(
                    $files,
                    static fn(string $file): bool => str_contains(strtolower(basename($file, '.php')), $needle)
                ));

            if ($files === []) {
                throw new \RuntimeException("Seeder not found: {$target}");
            }
        }

        if ($files === []) {
            Prompt::info('No seeders found.');
            return;
        }

        Prompt::info('Seeding database.');
        $seedName = null;
        $startedAt = microtime(true);

        try {
            foreach ($files as $file) {
                $seedName = basename($file);
                $startedAt = microtime(true);
                Prompt::status($seedName, 'RUNNING');

                $seeder = require $file;
                if (!is_object($seeder) || !is_callable([$seeder, 'up'])) {
                    throw new \RuntimeException("Seeder must expose a public up() method: {$seedName}");
                }

                $this->runMigration(static function () use ($seeder): void {
                    $seeder->up();
                });

                Prompt::status($seedName, 'DONE', microtime(true) - $startedAt);
            }
        } catch (Throwable $e) {
            Prompt::status((string) $seedName, 'FAIL', microtime(true) - $startedAt);
            Prompt::message("Seeding failed: {$seedName} — {$e->getMessage()}", 'error');
            throw $e;
        }
    }

    /**
     * Resolve which applied migrations should be rolled back based on CLI options.
     *
     * @param array<string, mixed> $args
     * @param array<int, string> $appliedMigrations
     * @return array<int, string>
     */
    private function resolveMigrationsToRollback(array $args, array $appliedMigrations): array
    {
        if (isset($args['all']) && $args['all']) {
            return array_reverse($appliedMigrations);
        }

        if (isset($args['batch'])) {
            $batch = filter_var($args['batch'], FILTER_VALIDATE_INT);
            if ($batch === false || $batch < 1) {
                throw new \InvalidArgumentException('Rollback batch must be a positive integer.');
            }

            $stmt = $this->run("SELECT migration FROM {$this->table} WHERE batch = ? ORDER BY id DESC", [$batch]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        if (isset($args['step']) || isset($args['_args'][0])) {
            $steps = (int) ($args['step'] ?? $args['_args'][0]);
            if ($steps < 1) {
                throw new \InvalidArgumentException('Rollback steps must be a positive integer.');
            }

            return array_slice(array_reverse($appliedMigrations), 0, $steps);
        }

        $latestBatch = $this->run("SELECT MAX(batch) FROM {$this->table}")->fetchColumn();
        if ($latestBatch === false || $latestBatch === null) {
            return [];
        }

        $stmt = $this->run(
            "SELECT migration FROM {$this->table} WHERE batch = ? ORDER BY id DESC",
            [(int) $latestBatch]
        );

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** Require explicit approval before changing a production database. */
    private function confirmToProceed(array $args, string $action): bool
    {
        if (app_environment() !== 'production' || filter_var($args['force'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        Prompt::alert("Application is in production mode. You are about to $action; this may change or delete data.");

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
            DB::DRIVER_SQLITE => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                type TEXT NOT NULL DEFAULT 'migration',
                batch INTEGER NOT NULL,
                applied_at INTEGER NOT NULL
            )",
            DB::DRIVER_PGSQL => "CREATE TABLE IF NOT EXISTS {$this->table} (
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
