<?php

namespace Spark\Database\Query;

use Closure;
use PDO;
use PDOStatement;
use Spark\Contracts\Support\Arrayable;
use Spark\Database\Exceptions\QueryBuilderException;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function sprintf;

/**
 * Compiles and executes insert, update, delete, truncate, and raw write operations.
 *
 * @internal Composed into \Spark\Database\QueryBuilder.
 */
trait ExecutesWriteQueries
{
    /**
     * Inserts data into the database with optional configurations.
     *
     * @param array|Arrayable $data The data to insert (single record or multiple records)
     * @param array $config Optional configurations [
     *     'ignore' => bool,      // Skip errors on duplicate
     *     'replace' => bool,     // Replace existing records
     *     'conflict' => array,   // Conflict target columns (for ON CONFLICT)
     *     'update' => array,     // Columns to update on conflict
     * ]
     * @return int Returns last insert ID.
     * @throws QueryBuilderException
     */
    public function insert(array|Arrayable $data, array $config = []): int
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        if (empty($data)) {
            return 0;
        }

        $started = microtime(true); // Start timing the query execution
        $startedMemory = memory_get_usage(true);

        // Normalize data to always be an array of records
        $data = !array_is_list($data) && !(isset($data[0]) && is_array($data[0])) ? [$data] : $data;

        $fields = $this->getInsertFields($data);

        // Generate the SQL statement
        $sql = $this->compileInsert($data, $config, $fields);

        // Prepare the statement
        $statement = $this->database->prepare($sql);
        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind all values
        $this->bindInsertValues($statement, $data, $fields);

        // Execute the statement
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, $data);

        return (int) $this->database->getPdo()->lastInsertId();
    }

    /**
     * Upsert single/multiple records into the database with optional configurations.
     *
     * @param array|Arrayable $data
     * @param array $config
     * @return int
     */
    public function upsert(array|Arrayable $data, array $config = []): int
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        // Transform single records into multiple.
        if (!array_is_list($data) && !(isset($data[0]) && is_array($data[0]))) {
            $data = [$data];
        }

        // Add default update close, if provided none.
        if (!isset($config['conflict'])) {
            $config['conflict'] = ['id'];
        }

        // Add default update fields, if provided none.
        if (!isset($config['update'])) {
            // Extract all fields except those are in $config['conflict'].
            $fields = array_filter(
                $this->getInsertFields($data),
                fn($field) => !in_array($field, $config['conflict'])
            );

            // Add extracted fields to be updated on conflict.
            $config['update'] = array_merge(...array_map(fn($field) => [$field => $field], $fields));
        }

        // Returns to base insert method. integer on success else, 0 on fails.
        return $this->insert($data, $config);
    }

    /**
     * Updates records in the database based on specified data and conditions.
     *
     * @param array|Arrayable $data  Key-value pairs of columns and their respective values to update.
     * @param null|string|array|Arrayable|Closure $where  Optional WHERE clause to specify which records to update.
     * @return bool
     */
    public function update(array|Arrayable $data, null|string|array|Arrayable|Closure $where = null): int
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        if (empty($data)) {
            return 0;
        }

        // Apply related model condition if necessary
        if ($this->isUsingModel()) {
            $model = $this->getModelBeingUsed();
            $model->fill($data);

            // Cast the data for storage, including timestamps if applicable
            $data = $model->castDataForStorage($data);

            // Add timestamps if the model uses them
            $timestamps = $model->getCastedTimestampsForStorage();
            if (!empty($timestamps)) {
                $data = [...$data, ...$timestamps];
            }

            $this->applyModelPrimaryCondition();
        }

        // Apply WHERE condition if provided
        $this->where($where);

        // Abort if no WHERE condition is set to avoid accidental updates on all records
        if (!$this->hasWhere()) {
            return 0;
        }

        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        // Prepare the table name
        $table = $this->getTableName();

        // Prepare the SQL update statement
        $setBindings = [];
        $sql = sprintf("UPDATE $table SET %s %s", $this->compileUpdateSet($data, $setBindings), $this->getWhereSql());
        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind the values for update
        foreach ($setBindings as $placeholder => $val) {
            $statement->bindValue(
                param: ":$placeholder",
                value: $this->castValue($val),
                type: $this->getParameterType($val)
            );
        }

        // Bind the WHERE clause parameters
        $this->bindParameters($statement);

        // Execute the statement and reset the WHERE clause
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, $data);

        $this->resetWhere();

        // Returns the number of affected rows.
        $count = $statement->rowCount();

        if ($this->isUsingModel()) {
            if ($count) {
                $this->getModelBeingUsed()->trackUpdated();
            } else {
                $this->getModelBeingUsed()->restoreOriginal();
            }
        }

        return $count; // Number of affected rows
    }

    /**
     * Deletes records from the database based on specified conditions.
     *
     * @param null|string|array|Arrayable|Closure $where  Optional WHERE clause to specify which records to delete.
     * @return int Returns the number of affected rows.
     */
    public function delete(null|string|array|Arrayable|Closure $where = null): int
    {
        // Apply related model condition if necessary
        if ($this->isUsingModel()) {
            $this->applyModelPrimaryCondition();
        }

        // Apply WHERE condition if provided
        $this->where($where);

        // Abort if no WHERE condition is set to avoid accidental deletion of all records
        if (!$this->hasWhere()) {
            return 0;
        }

        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        // Prepare the table name
        $table = $this->getTableName();

        // Prepare the SQL delete statement
        $sql = "DELETE FROM $table {$this->getWhereSql()}";
        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind the WHERE clause parameters
        $this->bindParameters($statement);

        // Execute the statement and reset the WHERE clause
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, []);

        // Reset current query builder.
        $this->resetWhere();

        // Returns the number of affected rows.
        $deleted = $statement->rowCount();

        if ($deleted && $this->isModelWithPrimaryBeingUsed()) {
            $this->getModelBeingUsed()->trackDeleted();
        }

        return $deleted;
    }

    /**
     * Truncates the current table.
     *
     * This method removes all records from the table without logging individual row deletions.
     * It is faster than a DELETE statement and resets any auto-increment counters.
     *
     * @return int Returns the number of affected rows.
     * @throws QueryBuilderException If the statement preparation or execution fails.
     */
    public function truncate(): int
    {
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        // Prepare the table name
        $table = $this->getTableName();
        $sql = $this->database->isSQLite() ? "DELETE FROM $table" : "TRUNCATE TABLE $table";

        // Prepare the SQL truncate statement
        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Execute the statement
        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, []);

        $count = $statement->rowCount();

        if ($this->database->isSQLite()) {
            try {
                $this->database->statement(
                    'DELETE FROM sqlite_sequence WHERE name = :table',
                    ['table' => $this->prefix . $this->table]
                );
            } catch (\Throwable) {
                // sqlite_sequence exists only after an AUTOINCREMENT table has been created.
            }
        }

        return $count;
    }

    /**
     * Execute a raw SQL query and return results.
     *
     * @param string $sql The raw SQL query to execute.
     * @param array $bindings Optional bindings for the SQL query.
     * @return array
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        // Bind parameters
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $statement->bindValue(
                    param: $key + 1,
                    value: $this->castValue($value),
                    type: $this->getParameterType($value)
                );
            } else {
                $statement->bindValue(
                    param: $this->normalizeNamedBinding($key),
                    value: $this->castValue($value),
                    type: $this->getParameterType($value)
                );
            }
        }

        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->log($started, $startedMemory, $sql, $bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create or update a record.
     *
     * @param array $attributes Attributes to search by.
     * @param array $values Values to update or create with.
     * @return int|bool Returns last inserted ID on insert, number of affected rows on update, or false on failure.
     */
    public function updateOrInsert(array $attributes, array $values = []): int|bool
    {
        // Check if record exists
        if ((clone $this)->where($attributes)->exists()) {
            if ($values === []) {
                return true;
            }

            // Update existing record
            return $this->where($attributes)->update($values);
        } else {
            // Insert new record
            return $this->insert([...$attributes, ...$values]);
        }
    }

    /**
     * Insert a new record and return the model.
     *
     * @param array $data
     * @return mixed
     */
    public function create(array $data): mixed
    {
        $id = $this->insert($data);

        if ($id) {
            return ['id' => $id, ...$data]; // Return the newly created record with ID
        }

        return false;
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @param string $column
     * @param int $value
     * @param mixed $where
     * @return bool
     */
    public function increment(string $column, int $value = 1, $where = null): bool
    {
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        $this->where($where);

        $bindings = ['increment' => $value, ...$this->getBindings()];
        $sql = "UPDATE " . $this->getTableName()
            . " SET {$this->wrapper->wrapColumn($column)} = {$this->wrapper->wrapColumn($column)} + :increment "
            . $this->getWhereSql();

        $result = $this->executeAffectingStatement($sql, $bindings);

        $this->log($started, $startedMemory, $sql, $bindings);

        return $result > 0;
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param string $column
     * @param int $value
     * @param mixed $where
     * @return bool
     */
    public function decrement(string $column, int $value = 1, $where = null): bool
    {
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        $this->where($where);

        $bindings = ['decrement' => $value, ...$this->getBindings()];
        $sql = "UPDATE " . $this->getTableName()
            . " SET {$this->wrapper->wrapColumn($column)} = {$this->wrapper->wrapColumn($column)} - :decrement "
            . $this->getWhereSql();

        $result = $this->executeAffectingStatement($sql, $bindings);

        $this->log($started, $startedMemory, $sql, $bindings);

        return $result > 0;
    }

    /**
     * Compile the SET clause for an update query with isolated placeholders.
     *
     * @param array $data
     * @param array $bindings
     * @return string
     */
    private function compileUpdateSet(array $data, array &$bindings): string
    {
        $sets = [];

        foreach ($data as $column => $value) {
            $placeholder = $this->makeParameterName("set_$column");
            $sets[] = $this->wrapper->wrapColumn($column) . " = :$placeholder";
            $bindings[$placeholder] = $value;
        }

        return implode(', ', $sets);
    }

    /**
     * Execute an INSERT/UPDATE/DELETE style statement and return affected rows.
     *
     * @param string $sql
     * @param array $bindings
     * @return int
     */
    private function executeAffectingStatement(string $sql, array $bindings = []): int
    {
        $statement = $this->database->prepare($sql);

        if ($statement === false) {
            throw new QueryBuilderException('Failed to prepare statement');
        }

        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $statement->bindValue(
                    param: $key + 1,
                    value: $this->castValue($value),
                    type: $this->getParameterType($value)
                );
            } else {
                $statement->bindValue(
                    param: $this->normalizeNamedBinding($key),
                    value: $this->castValue($value),
                    type: $this->getParameterType($value)
                );
            }
        }

        if ($statement->execute() === false) {
            throw new QueryBuilderException('Failed to execute statement');
        }

        $this->resetWhere();

        return $statement->rowCount();
    }

    /**
     * Compiles the INSERT SQL statement based on configuration.
     *
     * @param array $data The data to be inserted.
     * @param array $config The configuration array.
     * @return string The compiled INSERT SQL statement.
     */
    private function compileInsert(array $data, array $config, array $fields): string
    {
        $table = $this->getTableName();

        // Base command (INSERT/REPLACE)
        $command = $this->getInsertCommand($config);

        // IGNORE modifier
        $ignore = $this->getIgnoreModifier($config);

        // Columns
        $columns = $this->wrapper->columnize($fields);

        // Values placeholders
        $values = $this->createPlaceholder($data, $fields);

        // ON CONFLICT/DUPLICATE KEY UPDATE clause
        $conflict = $this->compileConflictClause($config);

        return trim("$command $ignore INTO $table ($columns) VALUES $values $conflict");
    }

    /**
     * Gets the appropriate INSERT command based on configuration.
     *
     * @param array $config The configuration array.
     * @return string The INSERT command.
     */
    private function getInsertCommand(array $config): string
    {
        if (isset($config['replace']) && $config['replace'] === true) {
            return $this->database->isMySQL() ? 'REPLACE' : 'INSERT';
        }
        return 'INSERT';
    }

    /**
     * Gets the IGNORE modifier for the INSERT statement.
     *
     * @param array $config The configuration array.
     * @return string The IGNORE modifier.
     */
    private function getIgnoreModifier(array $config): string
    {
        if (!isset($config['ignore']) || $config['ignore'] !== true) {
            return '';
        }

        if ($this->database->isSQLite()) {
            return 'OR IGNORE';
        }

        if ($this->database->isMySQL()) {
            return 'IGNORE';
        }

        // PostgreSQL doesn't support IGNORE, we'll use ON CONFLICT DO NOTHING instead
        return '';
    }

    /**
     * Creates a placeholder string for the INSERT statement.
     *
     * @param array $data The data to be inserted.
     * @return string The placeholder string.
     */
    private function createPlaceholder(array $data, array $fields): string
    {
        $placeholders = [];
        foreach ($data as $serial => $row) {
            $placeholders[] = '(' . implode(',', array_map(fn($column) => ':' . $column . '_' . $serial, $fields)) . ')';
        }
        return implode(',', $placeholders);
    }

    /**
     * Get the normalized column list for one or more inserted rows.
     *
     * @param array $data
     * @return array
     */
    private function getInsertFields(array $data): array
    {
        $fields = [];

        foreach ($data as $row) {
            $fields = [...$fields, ...array_keys($row)];
        }

        return array_values(array_unique($fields));
    }

    /**
     * Compiles the conflict resolution clause.
     *
     * @param array $config The configuration array.
     * @return string The compiled conflict resolution clause.
     */
    private function compileConflictClause(array $config): string
    {
        if (empty($config['update'])) {
            // For PostgreSQL with ignore but no update, use DO NOTHING
            if ($this->database->isPostgreSQL() && isset($config['ignore']) && $config['ignore'] === true) {
                $conflictColumns = $this->wrapper->columnize($config['conflict'] ?? ['id']);
                return "ON CONFLICT ($conflictColumns) DO NOTHING";
            }
            return '';
        }

        $conflictColumns = $this->wrapper->columnize($config['conflict'] ?? ['id']);
        $updates = [];

        foreach ($config['update'] as $key => $value) {
            if (is_int($key)) {
                $key = $value; // If key is an integer, use the value as the key
            }

            if ($this->database->isPostgreSQL()) {
                $updates[] = $this->wrapper->wrapColumn($key) . ' = EXCLUDED.' . $this->wrapper->wrapColumn($value);
            } elseif ($this->database->isMySQL()) {
                $updates[] = $this->wrapper->wrapColumn($key) . ' = VALUES(' . $this->wrapper->wrapColumn($value) . ')';
            } elseif ($this->database->isSQLite()) {
                $updates[] = $this->wrapper->wrapColumn($key) . ' = excluded.' . $this->wrapper->wrapColumn($value);
            }
        }

        if ($this->database->isPostgreSQL() || $this->database->isSQLite()) {
            return "ON CONFLICT ($conflictColumns) DO UPDATE SET " . implode(', ', $updates);
        }

        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    /**
     * Binds all values for the insert statement.
     *
     * @param PDOStatement $statement The PDO statement.
     * @param array $data The data to bind.
     */
    private function bindInsertValues(PDOStatement $statement, array $data, array $fields): void
    {
        foreach ($data as $serial => $row) {
            foreach ($fields as $column) {
                $value = $row[$column] ?? null;

                $statement->bindValue(
                    param: sprintf(':%s_%s', $column, $serial),
                    value: $this->castValue($value),
                    type: $this->getParameterType($value)
                );
            }
        }
    }

    /**
     * Determines the PDO parameter type for a value.
     *
     * @param mixed $value The value to determine the parameter type for.
     * @return int The PDO parameter type.
     */
    private function getParameterType(mixed $value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        if ($value === null) {
            return PDO::PARAM_NULL;
        }
        return PDO::PARAM_STR;
    }
}
