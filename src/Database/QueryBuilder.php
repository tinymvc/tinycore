<?php

namespace Spark\Database;

use Spark\Utils\Paginator;
use PDO;
use PDOStatement;
use Exception;

/**
 * Class Query
 *
 * This class provides methods to build and execute SQL queries for CRUD operations and 
 * joins in a structured and dynamic way.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class QueryBuilder
{
    /**
     * Holds the SQL and bind parameters for the WHERE clause.
     * 
     * @var array
     */
    private array $where = ['sql' => '', 'bind' => []];

    /**
     * Holds the SQL structure, join conditions, and join count.
     * 
     * @var array
     */
    private array $query = ['sql' => '', 'joins' => '', 'join_num' => 0];

    /**
     * Array to store data mappers for processing retrieved data.
     * 
     * @var array
     */
    private array $dataMapper = [];

    /**
     * Holds the table name to be used for the query.
     * 
     * @var string
     */
    private string $table;

    /**
     * Constructor for the query class.
     *
     * Initializes the query object with a database instance, 
     * which is used for executing SQL queries.
     *
     * @param DB $database The database instance to be used for query execution.
     */
    public function __construct(private DB $database)
    {
    }

    /**
     * Sets the table name to be used for the query.
     * 
     * @param string $table The table name to set.
     * @return self
     */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Adds a data mapper callback to process query results.
     * 
     * @param callable $callback The callback function to process data.
     * @return self Returns the query object.
     */
    public function addMapper(callable $callback): self
    {
        $this->dataMapper[] = $callback;
        return $this;
    }

    /**
     * Inserts data into the database with optional configurations.
     * 
     * @param array $data The data to insert.
     * @param array $config Optional configurations ['ignore' => false, 'replace' => false, 'conflict' => ['id'], 'update' => []]
     * @return int
     */
    public function insert(array $data, array $config = []): int
    {
        // Ignore insert when data is empty.
        if (empty($data)) {
            return 0;
        }

        // Transform Single record into multiple.
        if (!(isset($data[0]) && is_array($data[0]))) {
            $data = [$data];
        }

        // Extract database tables fields from first record.
        $fields = array_keys($data[0]);

        // Create and run sql insert command.
        $statement = $this->database->prepare(
            sprintf(
                // sql insert command.
                "%s %s INTO {$this->table} (%s) VALUES %s %s;",

                // create or replace data into database 
                isset($config['replace']) && $config['replace'] === true ?
                'REPLACE' : 'INSERT',

                // use ignore when failed
                isset($config['ignore']) && $config['ignore'] === true ?
                ($this->database->getConfig('driver') === 'sqlite' ? 'OR IGNORE' : 'IGNORE') : '',

                // join all the database table field using "," comma.
                join(',', $fields),

                // use placeholder of records and bind value later, to avoid sql injection.
                $this->createPlaceholder($data),

                // bulk update database records on conflict.
                isset($config['update']) && !empty($config['update']) ?
                ($this->database->getConfig('driver') === 'sqlite' ?
                        // bulk update records when pdo driver is sqlite.
                    ('ON CONFLICT(' . join(',', $config['conflict'] ?? ['id']) . ') DO UPDATE SET ' . (join(
                        ', ',
                        array_map(
                            fn($key, $value) => sprintf('%s = excluded.%s', $key, $value),
                            array_keys($config['update']),
                            array_values($config['update'])
                        )
                    )))
                    // bulk update records when pdo driver is mysql.
                    : ('ON DUPLICATE KEY UPDATE ' . (join(
                        ', ',
                        array_map(
                            fn($key, $value) => sprintf('%s = VALUES(%s)', $key, $value),
                            array_keys($config['update']),
                            array_values($config['update'])
                        )
                    )))
                ) : ''
            )
        );

        if ($statement === false) {
            throw new Exception('Failed to prepare statement');
        }

        // Bind records value into statement.
        foreach ($data as $serial => $row) {
            foreach ($fields as $column) {
                $statement->bindValue(
                    sprintf(':%s_%s', $column, $serial),
                    isset($row[$column]) && is_array($row[$column]) ?
                    ($row[$column]['text'] ?? null) : ($row[$column] ?? null)
                );
            }
        }

        // Execute insert query command.
        if ($statement->execute() === false) {
            throw new Exception('Failed to execute statement');
        }

        // Returns the last inserted ID.
        return $this->database->getPdo()->lastInsertId();
    }

    /**
     * Update multiple records into the database with optional configurations.
     * 
     * @param array $data 
     * @param array $config 
     * @return int 
     */
    public function bulkUpdate(array $data, array $config = []): int
    {
        // Transform single records into multiple.
        if (!(isset($data[0]) && is_array($data[0]))) {
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
                array_keys($data[0]),
                fn($field) => !in_array($field, $config['conflict'])
            );

            // Add extracted fields to be updated on conflict.
            $config['update'] = array_merge(...array_map(fn($field) => [$field => $field], $fields));
        }

        // Returns to base insert method. integer on success else, 0 on fails. 
        return $this->insert($data, $config);
    }

    /**
     * Add a where clause to the query.
     *
     * @param string|array $column 
     *   The column name to query, or an array of column names.
     * @param string|null $operator 
     *   The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value 
     *   The value to query. If null, the value will be determined
     *   based on the operator given.
     * @param string $type 
     *   The type of where clause to add. May be 'AND' or 'OR'.
     * @return self
     */
    public function where(string|array $column = null, ?string $operator = null, $value = null, string $type = 'AND'): self
    {
        if ($column !== null) {
            return $this->addWhere($type, $column, $operator, $value);
        }

        return $this;
    }

    /**
     * Add an AND where clause to the query.
     *
     * @param string|array $column
     *   The column name to query, or an array of column names.
     * @param string|null $operator
     *   The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value
     *   The value to query. If null, the value will be determined
     *   based on the operator given.
     * @return self
     */
    public function andWhere(string|array $column = null, ?string $operator = null, $value = null): self
    {
        return $this->addWhere('AND', $column, $operator, $value);
    }


    /**
     * Add an OR where clause to the query.
     *
     * @param string|array $column
     *   The column name to query, or an array of column names.
     * @param string|null $operator
     *   The operator to use. If null, the operator will be determined
     *   based on the value given.
     * @param mixed $value
     *   The value to query. If null, the value will be determined
     *   based on the operator given.
     * @return self
     *   Returns the current instance for method chaining.
     */
    public function orWhere(string|array $column = null, ?string $operator = null, $value = null): self
    {
        return $this->addWhere('OR', $column, $operator, $value);
    }

    /**
     * Updates records in the database based on specified data and conditions.
     *
     * @param array $data  Key-value pairs of columns and their respective values to update.
     * @param mixed $where  Optional WHERE clause to specify which records to update.
     * @return bool
     */
    public function update(array $data, mixed $where = null): bool
    {
        // Apply WHERE condition if provided
        $this->where($where);

        // Abort if no WHERE condition is set to avoid accidental updates on all records
        if (!$this->hasWhere()) {
            return false;
        }

        // Prepare the SQL update statement
        $statement = $this->database->prepare(
            sprintf(
                "UPDATE {$this->table} SET %s %s",
                implode(', ', array_map(fn($attr) => "$attr=:$attr", array_keys($data))),
                $this->getWhereSql()
            )
        );

        if ($statement === false) {
            throw new Exception('Failed to prepare statement');
        }

        // Bind the values for update
        foreach ($data as $key => $val) {
            $statement->bindValue(":$key", $val);
        }

        // Bind the WHERE clause parameters
        $this->bindWhere($statement);

        // Execute the statement and reset the WHERE clause
        if ($statement->execute() === false) {
            throw new Exception('Failed to execute statement');
        }

        $this->resetWhere();

        // Returns true if records are successfully updated, false otherwise.
        return $statement->rowCount() > 0;
    }

    /**
     * Deletes records from the database based on specified conditions.
     *
     * @param mixed $where  Optional WHERE clause to specify which records to delete.
     * @return bool
     */
    public function delete(mixed $where = null): bool
    {
        // Apply WHERE condition if provided
        $this->where($where);

        // Abort if no WHERE condition is set to avoid accidental deletion of all records
        if (!$this->hasWhere()) {
            return false;
        }

        // Prepare the SQL delete statement
        $statement = $this->database->prepare("DELETE FROM {$this->table} {$this->getWhereSql()}");

        if ($statement === false) {
            throw new Exception('Failed to prepare statement');
        }

        // Bind the WHERE clause parameters
        $this->bindWhere($statement);

        // Execute the statement and reset the WHERE clause
        if ($statement->execute() === false) {
            throw new Exception('Failed to execute statement');
        }

        // Reset current query builder.
        $this->resetWhere();

        // Returns true if records are successfully deleted, false otherwise.
        return $statement->rowCount() > 0;
    }

    /**
     * Specify the fields to include in the SELECT clause.
     *
     * @param array|string $fields A string or an array of column names to select.
     * @return self The current instance for method chaining.
     */
    public function select(array|string $fields = '*'): self
    {
        // Convert array of fields to a comma-separated string if necessary
        if (is_array($fields)) {
            $fields = implode(',', $fields);
        }

        // Build the FROM clause
        $table = isset($this->table) ? "FROM {$this->table}" : '';

        // Build the initial SELECT SQL query
        $this->query['sql'] = "SELECT {$fields} {$table}";

        // Returns the current instance for method chaining.
        return $this;
    }

    /**
     * Sets an alias for the current table in the FROM clause.
     *
     * If the given alias does not contain the 'AS ' keyword, it will be prepended.
     *
     * @param string $alias The alias for the table.
     *
     * @return self The current instance for method chaining.
     */
    public function as(string $alias): self
    {
        $this->query['alias'] = $alias !== '' ? stripos($alias, 'AS ') === false ? " AS {$alias} " : " {$alias} " : '';
        return $this;
    }

    /**
     * Adds an INNER JOIN clause to the query.
     *
     * @param string $table  The table to join.
     * @param string $condition  The join condition.
     * @return self
     */
    public function join(string $table, string $condition): self
    {
        return $this->addJoin('INNER', $table, $condition);
    }

    /**
     * Adds a LEFT JOIN clause to the query.
     *
     * @param string $table  The table to join.
     * @param string $condition  The join condition.
     * @return self
     */
    public function leftJoin(string $table, string $condition): self
    {
        return $this->addJoin('LEFT', $table, $condition);
    }

    /**
     * Adds a RIGHT JOIN clause to the query.
     *
     * @param string $table  The table to join.
     * @param string $condition  The join condition.
     * @return self
     */
    public function rightJoin(string $table, string $condition): self
    {
        return $this->addJoin('RIGHT', $table, $condition);
    }

    /**
     * Adds a CROSS JOIN clause to the query.
     *
     * @param string $table  The table to join.
     * @param string $condition  The join condition.
     * @return self
     */
    public function crossJoin(string $table, string $condition): self
    {
        return $this->addJoin('CROSS', $table, $condition);
    }

    /**
     * Sets the ordering clause for the query.
     *
     * @param ?string $sort Order by clause as a string (e.g., 'field ASC').
     * @return self
     */
    public function order(?string $sort = null): self
    {
        if ($sort !== null) {
            $this->query['order'] = $sort;
        }

        return $this;
    }

    /**
     * Sets ascending order for a specified field.
     *
     * @param string $field Field to order by in ascending order, defaults to 'id'.
     * @return self
     */
    public function orderAsc(string $field = 'id'): self
    {
        $this->query['order'] = "$field ASC";
        return $this;
    }

    /**
     * Sets descending order for a specified field.
     *
     * @param string $field Field to order by in descending order, defaults to 'id'.
     * @return self
     */
    public function orderDesc(string $field = 'id'): self
    {
        $this->query['order'] = "$field DESC";
        return $this;
    }

    /**
     * Sets the GROUP BY clause for the query.
     *
     * @param string $group Group by clause as a string.
     * @return self
     */
    public function group(string $group): self
    {
        $this->query['group'] = $group;
        return $this;
    }

    /**
     * Sets the HAVING clause for the query.
     *
     * @param string $having Having clause as a string.
     * @return self
     */
    public function having(string $having): self
    {
        $this->query['having'] = $having;
        return $this;
    }

    /**
     * Sets a limit and optional offset for the query.
     *
     * @param int|null $offset Starting point for the query, if specified.
     * @param int|null $limit Number of records to fetch.
     * @return self
     */
    public function limit(?int $offset = null, ?int $limit = null): self
    {
        if ($offset !== null) {
            $this->query['limit'] = sprintf(" %s%s", $offset, $limit !== null ? ", $limit" : '');
        }

        return $this;
    }

    /**
     * Specifies the fetch mode(s) for the query results.
     *
     * @param mixed ...$fetch PDO fetch styles (e.g., PDO::FETCH_ASSOC).
     * @return self
     */
    public function fetch(...$fetch): self
    {
        $this->query['fetch'] = $fetch;
        return $this;
    }

    /**
     * Retrieves the first result from the query.
     *
     * @return mixed
     */
    public function first()
    {
        // Execute current select query by limiting to single record.
        $this->limit(1)->executeSelectQuery();

        // Fetch first record from database and apply mapper if exists.
        $result = $this->applyMapper(
            $this->getStatement()
                ->fetchAll(
                    ...$this->query['fetch'] ?? [PDO::FETCH_OBJ]
                )
        );

        // Reset current query builder.
        $this->resetQuery();

        // The first result as an object or false if none found.
        return $result[0] ?? false;
    }

    /**
     * Retrieves the last result by applying descending order and fetching the first.
     *
     * @return mixed
     */
    public function last()
    {
        // The last result as an object or false if none found.
        return $this->orderDesc()->first();
    }

    /**
     * Retrieves the latest results by ordering in descending order.
     *
     * @return array
     */
    public function latest(): array
    {
        // Array of the latest results.
        return $this->orderDesc()->result();
    }

    /**
     * Retrieves all results from the executed query.
     *
     * @return array Array of query results.
     */
    public function result(): array
    {
        // Execute current sql swlwct command.
        $this->executeSelectQuery();

        // Fetch all results from database.
        $result = $this->getStatement()
            ->fetchAll(
                ...$this->query['fetch'] ?? [PDO::FETCH_OBJ]
            );

        // Reset current query builder.
        $this->resetQuery();

        // Apply data mapper if exists in current query.
        return $this->applyMapper($result);
    }

    /**
     * Paginates query results.
     *
     * @param int $limit Number of items per page.
     * @param string $keyword URL query parameter name for pagination.
     * @return Paginator
     */
    public function paginate(int $limit = 10, string $keyword = 'page'): Paginator
    {
        // Select records & Create a paginator object.
        if (empty($this->query['sql'])) {
            $this->select();
        }

        $paginator = get(Paginator::class);
        $paginator->limit = $limit;
        $paginator->keyword = $keyword;

        // Count total records from exisitng command only for serverside database driver.
        if ($this->database->getConfig('driver') !== 'sqlite') {
            $this->query['sql'] = preg_replace('/SELECT /', 'SELECT SQL_CALC_FOUND_ROWS ', $this->query['sql'], 1);
        }

        // Set pagination count to limit database records, and execute query.
        $this->limit(
            ceil($limit * ($paginator->getKeywordValue() - 1)),
            $limit
        )
            ->executeSelectQuery();

        // Get total record count, from sqlite database and update it to paginator class.
        if ($this->database->getConfig('driver') === 'sqlite') {
            $paginator->total = $this->count();
        } else {
            // Get number of records from exisitng query command.
            $total = $this->database->prepare('SELECT FOUND_ROWS()');
            $total->execute();

            // Update number of items into paginator class.
            $paginator->total = $total->fetch(PDO::FETCH_COLUMN);
        }

        // Set database records into paginator class.
        $paginator->setData(
            $this->applyMapper(
                $this->getStatement()
                    ->fetchAll(...$this->query['fetch'] ?? [PDO::FETCH_OBJ])
            )
        );

        // Re-initialize paginator pages.
        $paginator->resetPaginator();

        // Reset current query builder.
        $this->resetQuery();

        // A paginator instance containing paginated results.
        return $paginator;
    }

    /**
     * Counts the number of rows matching the current query.
     *
     * @return int The number of matching rows.
     */
    public function count(): int
    {
        // Create sql command to count rows.
        $statement = $this->database->prepare(
            "SELECT COUNT(1) FROM {$this->table}"
            . $this->getAlias()
            . $this->query['joins']
            . $this->getWhereSql()
            . (isset($this->query['group']) ? ' GROUP BY ' . trim($this->query['group']) : '')
            . (isset($this->query['having']) ? ' HAVING ' . trim($this->query['having']) : '')
        );

        // Apply where statement if exists.
        $this->bindWhere($statement);

        // Execute sql command.
        $statement->execute();

        // Returns number of found rows.
        return $statement->fetch(PDO::FETCH_COLUMN);
    }

    /** @internal helpers methods for this query builder class */

    /**
     * Applies all data mappers to a dataset.
     * 
     * @param array $data Data to process.
     * @return array Processed data after all mappers are applied.
     */
    private function applyMapper(array $data): array
    {
        foreach ($this->dataMapper as $key => $mapper) {
            unset($this->dataMapper[$key]);
            $data = call_user_func($mapper, $data);
        }

        return $data;
    }

    /**
     * Adds a join clause to the query with a specified join type.
     *
     * @param string $type  The type of join (INNER, LEFT, RIGHT, CROSS).
     * @param string $table  The table to join.
     * @param string $condition  The join condition.
     * @return self
     */
    private function addJoin(string $type, string $table, string $condition): self
    {
        // Generate an alias for the joined table
        $alias = sprintf('t%s', ++$this->query['join_num']);

        // Build and add the join clause to the SQL query
        $this->query['joins'] .= sprintf(
            " %s JOIN %s %s ON %s ",
            $type,
            $table,
            stripos($table, ' AS ') === false ? " AS $alias" : '',
            $condition
        );

        // Returns the current instance for method chaining.
        return $this;
    }

    /**
     * Creates placeholders for SQL values in bulk insertions.
     * 
     * @param array $data The data array for placeholders.
     * @return string
     */
    private function createPlaceholder(array $data): string
    {
        // Holds all records, to be going to inserted into the database.
        $values = [];

        // Add placeholders on each records, instead of actual value.
        foreach ($data as $serial => $row) {

            // Create dynamic placeholder, depands on parameters.
            $params = array_map(
                fn($attr, $value) => sprintf(
                    '%s:%s_%s%s',

                    // create placeholder from array value ex: ['prefix' => 'DATE('].
                    is_array($value) && isset($value['prefix']) ?
                    $value['prefix'] : '',

                    // Placeholder main part.
                    $attr,
                    $serial,

                    // create placeholder from array value ex: ['suffix' => ')'].
                    is_array($value) && isset($value['suffix']) ?
                    $value['suffix'] : ''
                ),
                array_keys($row),
                array_values($row)
            );

            // Push this record by "," comma into $values.
            $values[] = join(',', $params);
        }

        // Returns a string of placeholders for the SQL statement.
        return '(' . join('), (', $values) . ')';
    }

    /**
     * Executes a SELECT query with the built query parts.
     *
     * @return void
     */
    private function executeSelectQuery(): void
    {
        // Prepare select command.
        if (empty($this->query['sql'])) {
            $this->select();
        }

        // Build complete select command with condition, order, and limit.
        $statement = $this->database->prepare(
            $this->query['sql']
            . $this->getAlias()
            . $this->query['joins']
            . $this->getWhereSql()
            . (isset($this->query['group']) ? ' GROUP BY ' . trim($this->query['group']) : '')
            . (isset($this->query['having']) ? ' HAVING ' . trim($this->query['having']) : '')
            . (isset($this->query['order']) ? ' ORDER BY ' . trim($this->query['order']) : '')
            . (isset($this->query['limit']) ? ' LIMIT ' . trim($this->query['limit']) : '')
        );

        if ($statement === false) {
            throw new Exception('Failed to prepare statement');
        }

        // Bind/Add conditions to filter records.
        $this->bindWhere($statement);

        // Execute current select command.
        if ($statement->execute() === false) {
            throw new Exception('Failed to execute statement');
        }

        // Set select statement into query to modify dynamically.
        $this->query['statement'] = $statement;

    }

    /**
     * Get the PDOStatement of the last query
     *
     * @return PDOStatement The PDOStatement of the last query or false if no query has been executed
     */
    private function getStatement(): PDOStatement
    {
        return $this->query['statement'];
    }


    /**
     * Adds a WHERE clause to the current SQL query.
     *
     * This method allows building dynamic WHERE clauses using method chaining.
     * It supports single column conditions, array-based conditions for complex
     * clauses, and simple string clauses.
     *
     * @param string $method The logical method (e.g., WHERE, AND, OR) to use.
     * @param string|array|null $column The column name or array of column-value pairs.
     * @param string|null $operator The operator for the WHERE clause (e.g., '=', 'LIKE').
     * @param mixed|null $value The value to compare the column to.
     * @return self Returns the current instance for method chaining.
     * @throws Exception If the provided arguments are invalid.
     */
    private function addWhere(string $method, string|array $column = null, ?string $operator = null, $value = null): self
    {
        // Holds a conditional clause for database.
        $command = '';

        if (is_string($column) && is_string($operator)) {
            // Create a where clause from column, operator, and value.
            // for example: "title like :title"
            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $command = sprintf(
                "%s %s %s :%s",
                $method,
                $column,
                $operator,
                str_replace('.', '', $column)
            );
            $this->where['bind'] = array_merge($this->where['bind'], [$column => $value]);
        } elseif (is_array($column) && $operator === null && $value === null) {
            // Create a where clause from array conditions.
            $command = sprintf(
                "%s %s",
                $method,
                implode(
                    " {$method} ",
                    array_map(
                        fn($attr, $value) => $attr . (is_array($value) ?
                            // Create a where clause to match IN(), Ex: "id IN(:id_0, :id_1, :id_2, :id_3)" .
                            sprintf(
                                " IN (%s)",
                                join(",", array_map(fn($index) => ':' . str_replace('.', '', $attr) . '_' . $index, array_keys($value)))
                            )
                            // Create a where close to match is equal, Ex. "id = :id_0"
                            : " = :" . str_replace('.', '', $attr)
                        ),
                        array_keys($column),
                        array_values($column)
                    )
                )
            );

            // Append where clause binding values, safe & GOOD PDO practice.
            $this->where['bind'] = array_merge($this->where['bind'], $column);
        } elseif (is_string($column) && $operator === null && $value === null) {
            // Simply add a where clause from string.
            $command = "{$method} {$column}";
        } else {
            throw new Exception('Invalid where clause');
        }

        // Register the where clause into current query builder.
        $this->where['sql'] .= sprintf(
            ' %s ',
            empty($this->where['sql']) ? ltrim($command, "$method ") : $command
        );

        // Returns the current instance for method chaining.
        return $this;
    }

    /**
     * Get the alias for the current table in the query.
     *
     * Returns the alias set in the query builder, or " AS p " if joins are defined,
     * or an empty string if neither of the above conditions are met.
     *
     * @return string The alias for the current table in the query.
     */
    private function getAlias(): string
    {
        return $this->query['alias'] ?? (!empty($this->query['joins']) ? ' AS p ' : '');
    }

    /**
     * Checks if any conditions have been set in the WHERE clause.
     *
     * @return bool
     */
    private function hasWhere(): bool
    {
        // Returns true if conditions are set, otherwise false.
        return !empty(trim($this->where['sql']));
    }

    /**
     * Generates the SQL string for the WHERE clause based on conditions added.
     *
     * @return string
     */
    private function getWhereSql(): string
    {
        // Returns the SQL string for the WHERE clause.
        return $this->hasWhere() ? ' WHERE ' . trim($this->where['sql']) . ' ' : '';
    }

    /**
     * Binds the values of the WHERE clause conditions to the SQL statement.
     *
     * @param PDOStatement $statement The prepared PDO statement to bind values.
     * @return void
     */
    private function bindWhere(PDOStatement &$statement): void
    {
        // Bind where clause values to filter records.
        foreach ($this->where['bind'] ?? [] as $param => $value) {
            /** 
             * Create a placeholder of the parameter exactly added into the where clause.
             * Ex. "id = :id", ==> :id is the parameter.
             */
            $param = ':' . str_replace('.', '', $param);

            if (is_array($value)) {
                // binds clause values from a array condition, Ex. "id IN(1, 2, 3, 4)".
                foreach ($value as $index => $val) {
                    // Add multiple parameter into IN(), Ex. :id_0 => $value, :id_1 => $value;
                    $statement->bindValue("{$param}_$index", $val);
                }
            } else {
                // binds clause values from a string condition, Ex. "id = 1".
                $statement->bindValue($param, $value);
            }
        }
    }

    /**
     * Resets the WHERE clause and clears any existing conditions.
     *
     * @return void
     */
    private function resetWhere(): void
    {
        $this->where = ['sql' => '', 'bind' => []];
    }

    /**
     * Resets the query components for reuse.
     *
     * @return void
     */
    private function resetQuery(): void
    {
        // Reset Select query parameters.
        $this->query = ['sql' => '', 'joins' => '', 'join_num' => 0];

        // Reset where query parameters.
        $this->resetWhere();
    }
}
