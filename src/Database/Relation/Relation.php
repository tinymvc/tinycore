<?php

namespace Spark\Database\Relation;

use Spark\Database\Model;
use Spark\Database\QueryBuilder;

/**
 * Class Relation
 * 
 * This class serves as a base for defining relationships between models in a database.
 * It provides a common interface for accessing related models via QueryBuilder proxy pattern.
 * 
 * Supports method chaining: $post->comments()->where('approved', 1)->get()
 * 
 * @method QueryBuilder with($relations)
 * @method QueryBuilder withFiltered(string $relation, string|array $filters)
 * @method QueryBuilder has(string $relation, string $operator = '>=', int $count = 1)
 * @method QueryBuilder doesntHave(string $relation)
 * @method QueryBuilder whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1)
 * @method QueryBuilder whereDoesntHave(string $relation, ?Closure $callback = null)
 * @method QueryBuilder whereRelation(string $relation, string $column, string $operator = '=', $value = null)
 * @method QueryBuilder whereRelationIn(string $relation, string $column, array $values)
 * @method QueryBuilder whereRelationNotIn(string $relation, string $column, array $values)
 * @method QueryBuilder whereRelationNull(string $relation, string $column)
 * @method QueryBuilder whereRelationNotNull(string $relation, string $column)
 * @method QueryBuilder whereRelationLike(string $relation, string $column, string $pattern)
 * @method QueryBuilder whereRelationBetween(string $relation, string $column, $min, $max)
 * @method QueryBuilder whereRelationFindInSet(string $relation, string $column, $value)
 * @method QueryBuilder whereRelationJson(string $relation, string $column, string $key, $value)
 * @method QueryBuilder withCount(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1)
 * @method QueryBuilder withSum(string $relation, string $column, ?Closure $callback = null)
 * @method QueryBuilder withAvg(string $relation, string $column, ?Closure $callback = null)
 * @method QueryBuilder withMin(string $relation, string $column, ?Closure $callback = null)
 * @method QueryBuilder withMax(string $relation, string $column, ?Closure $callback = null)
 * @method QueryBuilder when(mixed $value, callable $callback)
 * @method QueryBuilder unless(mixed $value, callable $callback)
 * @method QueryBuilder where(null|string|array|Closure $column = null, ?string $operator = null, mixed $value = null, ?string $andOr = null, bool $not = false)
 * @method QueryBuilder whereRaw(string $sql, string|array $bindings = [])
 * @method QueryBuilder whereNull($where, $not = false)
 * @method QueryBuilder whereNotNull($where)
 * @method QueryBuilder whereIn(string $column, array $values)
 * @method QueryBuilder whereNotIn(string $column, array $values)
 * @method QueryBuilder whereContains(string $column, mixed $value)
 * @method QueryBuilder whereStartsWith(string $column, mixed $value)
 * @method QueryBuilder whereEndsWith(string $column, mixed $value)
 * @method QueryBuilder whereDate(string $column, string $operator, $value = null)
 * @method QueryBuilder whereYear(string $column, string $operator, $value = null)
 * @method QueryBuilder between($field, $value1, $value2, $type = '', $andOr = 'AND')
 * @method QueryBuilder notBetween($field, $value1, $value2)
 * @method QueryBuilder like($field, $data, $type = '', $andOr = 'AND')
 * @method QueryBuilder grouped(Closure $callback)
 * @method QueryBuilder select(array|string $fields = '*')
 * @method QueryBuilder selectRaw(string $sql, array $bindings = [])
 * @method QueryBuilder column(string $column)
 * @method QueryBuilder max($field, $name = null)
 * @method QueryBuilder min($field, $name = null)
 * @method QueryBuilder sum($field, $name = null)
 * @method QueryBuilder avg($field, $name = null)
 * @method QueryBuilder join(string $table, $field1 = null, $operator = null, $field2 = null, $type = '')
 * @method QueryBuilder order(string $sort)
 * @method QueryBuilder orderBy(string $field, string $sort = 'ASC')
 * @method QueryBuilder orderAsc(string $field = 'id')
 * @method QueryBuilder orderDesc(string $field = 'id')
 * @method QueryBuilder groupBy(string|array $field)
 * @method QueryBuilder groupByRaw(string $sql, array $bindings = [])
 * @method QueryBuilder having(string $having)
 * @method QueryBuilder take(int $limit)
 * @method QueryBuilder limit(?int $offset = null, ?int $limit = null)
 * @method QueryBuilder fetch(...$fetch)
 * @method QueryBuilder latest()
 * @method QueryBuilder oldest()
 * @method QueryBuilder random()
 * @method QueryBuilder distinct(?string $column = null)
 * @method int delete(mixed $where = null)
 * @method mixed first()
 * @method mixed firstOrFail()
 * @method mixed last()
 * @method false|Model find($value)
 * @method Model findOrFail($value)
 * @method bool destroy($value)
 * @method array all()
 * @method \Spark\Support\Collection get()
 * @method array raw(string $sql, array $bindings = [])
 * @method array pluck(string $column)
 * @method mixed value(string $column)
 * @method bool increment(string $column, int $value = 1, $where = null)
 * @method bool decrement(string $column, int $value = 1, $where = null)
 * @method int count()
 * @method \Spark\Utils\Paginator paginate(int $limit = 10, string $keyword = 'page')
 * @method \Spark\Support\Collection filter(?callable $callback = null)
 * @method \Spark\Support\Collection map(callable $callback)
 * @method \Spark\Support\Collection mapWithKeys(callable $callback)
 * 
 * @package Spark\Database\Relation
 */
abstract class Relation
{
    /**
     * The QueryBuilder instance for this relation.
     *
     * @var QueryBuilder|null
     */
    protected null|QueryBuilder $query = null;

    /**
     * Create a new Relation instance.
     * 
     * @param Model|null $model The model instance that this relationship belongs to.
     */
    public function __construct(protected null|Model $model = null)
    {
    }

    /**
     * Get the parent model instance.
     * 
     * @return Model|null
     */
    public function getParentModel(): null|Model
    {
        return $this->model;
    }

    /**
     * Build the base query for this relationship.
     * This method must be implemented by subclasses to set up the QueryBuilder
     * with appropriate constraints for the relationship type.
     * 
     * @return QueryBuilder
     */
    abstract protected function buildQuery(): QueryBuilder;

    /**
     * Get the QueryBuilder instance for this relation.
     * 
     * @return QueryBuilder
     */
    public function getQuery(): QueryBuilder
    {
        return $this->query ??= $this->buildQuery();
    }

    /**
     * Get the configuration for the relationship.
     * This method must be implemented by subclasses to return
     * the specific configuration for the relationship.
     * 
     * @return array The configuration for the relationship, which may 
     *      include related model class, foreign keys, owner keys, etc.
     */
    abstract public function getConfig(): array;

    /**
     * Proxy method calls to the underlying QueryBuilder.
     * 
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        $result = $this->getQuery()->$method(...$parameters);

        // Return $this for fluent interface if QueryBuilder returned itself
        if ($result instanceof QueryBuilder) {
            return $this;
        }

        return $result;
    }
}