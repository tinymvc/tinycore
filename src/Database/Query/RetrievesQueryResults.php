<?php

namespace Spark\Database\Query;

use PDO;
use Spark\Database\QueryBuilder;
use Spark\Exceptions\NotFoundException;
use Spark\Support\Collection;
use Spark\Utils\Paginator;

/**
 * Retrieves, aggregates, paginates, and inspects query results.
 *
 * @internal Composed into \Spark\Database\QueryBuilder.
 */
trait RetrievesQueryResults
{
    /**
     * Retrieves the first result from the query.
     *
     * @param null|string|array $fields Optional fields to select for the first result.
     * @return mixed
     */
    public function first(null|string|array $fields = null): mixed
    {
        $fields && $this->select($fields);

        // Execute current select query by limiting to single record.
        $this->take(1)->executeSelectQuery();

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
     * Retrieves the first result or throws an exception if not found.
     *
     * @param mixed $where Optional WHERE clause to filter results.
     * @param null|string|array $fields Optional fields to select for the first result.
     * @return mixed The first result object or throws NotFoundException.
     * @throws \Spark\Exceptions\NotFoundException If no results are found.
     */
    public function firstOrFail($where = null, $fields = null): mixed
    {
        $this->where($where);

        // Get the first result, or throw an exception if not found.
        $result = $this->first($fields);

        if ($result === false) {
            throw new NotFoundException('No results found for the query.');
        }

        return $result;
    }

    /**
     * Retrieves the last result by applying descending order and fetching the first.
     *
     * @return mixed
     */
    public function last(null|string|array $fields = null): mixed
    {
        return $this->orderDesc()->first($fields);
    }

    /**
     * Sets the query to order results by the latest created_at timestamp.
     *
     * @param string $field The field to order by.
     * @param null|string|array $fields Optional fields to select.
     * @return self
     */
    public function latest(string $field = 'created_at', $fields = null): QueryBuilder
    {
        $fields && $this->select($fields);
        return $this->orderDesc($this->withAlias($field));
    }

    /**
     * Sets the query to order results by the oldest created_at timestamp.
     * 
     * @param string $field The field to order by.
     * @param null|string|array $fields Optional fields to select.
     * @return self
     */
    public function oldest(string $field = 'created_at', $fields = null): QueryBuilder
    {
        $fields && $this->select($fields);

        return $this->orderAsc($this->withAlias($field));
    }

    /**
     * Sets the query to return results in random order.
     *
     * @return self
     */
    public function random(): QueryBuilder
    {
        $this->query['order'] = $this->database->isMySQL() ? 'RAND()' : 'RANDOM()';
        return $this;
    }

    /**
     * Retrieves all results from the executed query.
     *
     * @param null|string|array $fields Optional fields to select.
     * @return array Array of query results.
     */
    public function all($fields = null): array
    {
        $fields && $this->select($fields);

        // Execute current sql select command.
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
     * Retrieves all results from the executed query and returns them in a collection.
     *
     * @param null|string|array $fields Optional fields to select.
     * @return \Spark\Support\Collection Array of query results.
     */
    public function get($fields = null): Collection
    {
        return collect($this->all($fields));
    }

    /**
     * Paginates query results.
     *
     * @param int $limit Number of items per page.
     * @param string $keyword URL query parameter name for pagination.
     * @param null|string|array $fields Optional fields to select.
     * @return \Spark\Utils\Paginator
     */
    public function paginate(int $limit = 10, string $keyword = 'page', $fields = null): Paginator
    {
        $fields && $this->select($fields);

        // Select records & Create a paginator object.
        if (empty($this->query['select'])) {
            $this->select();
        }

        $paginator = new Paginator(limit: $limit, keyword: $keyword);

        // Count total records from existing command only for serverside database driver.
        if ($this->database->isMySQL()) {
            $this->query['select'] = "SQL_CALC_FOUND_ROWS {$this->query['select']}";
        }

        // Set pagination count to limit database records, and execute query.
        $this->limit(
            ceil($limit * ($paginator->keywordValue() - 1)),
            $limit
        )
            ->executeSelectQuery();

        // Get total record count, from sqlite database and update it to paginator class.
        if ($this->database->isMySQL()) {
            // Get number of records from exisitng query command.
            $total = $this->database->prepare('SELECT FOUND_ROWS()');
            $total->execute();

            // Update number of items into paginator class.
            $paginator->total = $total->fetch(PDO::FETCH_COLUMN);
        } else {
            $paginator->total = $this->count();
        }

        // Set database records into paginator class.
        $paginator->setData(
            $this->applyMapper(
                $this->getStatement()
                    ->fetchAll(...$this->query['fetch'] ?? [PDO::FETCH_OBJ])
            )
        );

        // Re-initialize paginator pages.
        $paginator->reset();

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
        $started = microtime(true); // Start timing the operation
        $startedMemory = memory_get_usage(true);

        $table = $this->getTableName(); // Get the table name with prefix if exists.

        $sql = "SELECT COUNT(1) FROM $table"
            . $this->query['alias']
            . $this->query['joins']
            . $this->getWhereSql()
            . (isset($this->query['group']) ? ' GROUP BY ' . trim($this->query['group']) : '')
            . (isset($this->query['having']) ? ' HAVING ' . trim($this->query['having']) : '');

        // Create sql command to count rows.
        $statement = $this->database->prepare($sql);

        // Apply where statement if exists.
        $this->bindParameters($statement);

        // Execute sql command.
        $statement->execute();

        $this->log($started, $startedMemory, $sql, []);

        // Returns number of found rows.
        return (int) $statement->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * Checks if any records exist based on the current query conditions.
     *
     * @return bool True if at least one record exists, false otherwise.
     */
    public function exists(): bool
    {
        $query = clone $this; // Clone the current query builder to avoid modifying the original query.

        return $query->selectRaw('EXISTS(SELECT 1)')
            ->fetchColumn()
            ->first() == 1;
    }

    /**
     * Checks if no records exist based on the current query conditions.
     *
     * @return bool True if no records exist, false otherwise.
     */
    public function notExists(): bool
    {
        return !$this->exists();
    }
}
