<?php

namespace Spark\Database\Traits;

use Closure;
use Spark\Database\Exceptions\InvalidOrmException;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Support\Str;

/**
 * ManageRelation Trait
 * 
 * Provides relationship filtering and querying capabilities for models.
 * This trait extends QueryBuilder functionality to work with model relationships.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 * @package Spark\Database\Relation
 */
trait ManageRelation
{
    /**
     * Eager load relationships.
     * 
     * This method allows you to eager load relationships for the model.
     * It accepts a string or an array of relationship names,
     * and returns a QueryBuilder instance with the relationships loaded.
     * 
     * Supports nested relationships using dot notation:
     * - with('posts.comments')
     * - with('posts.comments.author')
     * - with(['posts' => fn($q) => $q->latest(), 'posts.comments'])
     * 
     * @param array|string ...$relations
     * @return QueryBuilder
     */
    public function with(array|string ...$relations): QueryBuilder
    {
        $relations = is_array($relations[0]) ? $relations[0] : $relations;
        $model = $this->getRelatedModel();

        // Parse and organize nested relationships
        $parsed = $this->parseWithRelations($relations);

        foreach ($parsed as $name => $data) {
            $relationConfig = $model->getRelationshipConfig($name);
            if ($relationConfig) {
                $this->addMapper(fn($result) => $model->loadRelation($result, $relationConfig, $name, $data['constraints'], $data['nested']));
            }
        }

        return $this;
    }

    /**
     * Eager load relationships with filters.
     * 
     * This method allows you to eager load a relationship with specific filters applied.
     * It accepts the relationship name and an associative array of filters,
     * and returns a QueryBuilder instance with the filtered relationship loaded.
     * 
     * @param string $relation The relationship name
     * @param string|array $filters Associative array or raw SQL of filters to apply
     * @return QueryBuilder
     */
    public function withFiltered(string $relation, string|array $filters): QueryBuilder
    {
        return $this->with([
            $relation => fn($query) => $query->where($filters)
        ]);
    }

    /**
     * Eager load polymorphic relationships
     * 
     * This method allows you to eager load polymorphic relationships for the model.
     * It accepts the relationship name, a morph map, and optional type/id column names,
     * and returns a QueryBuilder instance with the polymorphic relationship loaded.
     * 
     * @param string $relation The polymorphic relationship name
     * @param array $morphMap Array mapping morph types to their relationships
     * @param string|null $typeColumn The morph type column name
     * @param string|null $idColumn The morph id column name
     * @return QueryBuilder
     */
    public function morphWith(string $relation, array $morphMap, ?string $typeColumn = null, ?string $idColumn = null): QueryBuilder
    {
        $model = $this->getRelatedModel();

        $this->addMapper(fn($data) => $model->loadMorphRelation($data, $relation, $morphMap, $typeColumn, $idColumn));

        return $this;
    }

    /**
     * Finds a model by its primary key ID.
     *
     * @param string|int $value The Unique Identifier of the model to retrieve.
     * @return false|Model The found model instance or false if not found.
     */
    public function find($value): false|Model
    {
        $model = $this->getRelatedModel();

        return $this
            ->where([$model::$primaryKey => $value])
            ->first();
    }

    /**
     * Finds a model by its primary key ID or throws an exception if not found.
     *
     * @param string|int $value The Unique Identifier of the model to retrieve.
     * @return Model The found model instance.
     * @throws \Spark\Exceptions\NotFoundException If the model is not found.
     */
    public function findOrFail($value): Model
    {
        $model = $this->getRelatedModel();

        return $this
            ->where([$model::$primaryKey => $value])
            ->firstOrFail();
    }

    /**
     * Deletes the model from the database by its primary key value.
     *
     * @param string|int $value The unique identifier of the model to delete.
     * @return bool True if deletion was successful, false otherwise.
     */
    public function destroy($value): bool
    {
        $model = $this->getRelatedModel();
        return $this->delete([$model::$primaryKey => $value]);
    }

    /**
     * Add a constraint based on the existence of a relationship.
     * 
     * @param string $relation The relationship name
     * @param string $operator The operator (>=, <, !=, etc.)
     * @param int $count The count to compare against
     * @param string $boolean The boolean operator (AND/OR)
     * @param Closure|null $callback Optional callback to add constraints to the relationship query
     * @return QueryBuilder
     */
    public function has(string $relation, string $operator = '>=', int $count = 1, string $boolean = 'AND', ?Closure $callback = null): QueryBuilder
    {
        $model = $this->getRelatedModel();
        $relationConfig = $model->getRelationshipConfig($relation);

        return $this->addHasConstraint($relationConfig, $relation, $operator, $count, $boolean, $callback);
    }

    /**
     * Add a constraint based on the non-existence of a relationship.
     * 
     * @param string $relation The relationship name
     * @param string $boolean The boolean operator (AND/OR)
     * @param Closure|null $callback Optional callback to add constraints to the relationship query
     * @return QueryBuilder
     */
    public function doesntHave(string $relation, string $boolean = 'AND', ?Closure $callback = null): QueryBuilder
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    /**
     * Add an OR constraint based on the existence of a relationship.
     * 
     * @param string $relation The relationship name
     * @param string $operator The operator (>=, <, !=, etc.)
     * @param int $count The count to compare against
     * @param Closure|null $callback Optional callback to add constraints to the relationship query
     * @return QueryBuilder
     */
    public function orHas(string $relation, string $operator = '>=', int $count = 1, ?Closure $callback = null): QueryBuilder
    {
        return $this->has($relation, $operator, $count, 'OR', $callback);
    }

    /**
     * Add an OR constraint based on the non-existence of a relationship.
     * 
     * @param string $relation The relationship name
     * @param Closure|null $callback Optional callback to add constraints to the relationship query
     * @return QueryBuilder
     */
    public function orDoesntHave(string $relation, ?Closure $callback = null): QueryBuilder
    {
        return $this->doesntHave($relation, 'OR', $callback);
    }

    /**
     * Add a constraint based on the existence of a relationship with additional where clauses.
     * 
     * @param string $relation The relationship name
     * @param Closure|null $callback Callback to add constraints to the relationship query
     * @param string $operator The operator (>=, <, !=, etc.)
     * @param int $count The count to compare against
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1, string $boolean = 'AND'): QueryBuilder
    {
        return $this->has($relation, $operator, $count, $boolean, $callback);
    }

    /**
     * Add a constraint based on the non-existence of a relationship with additional where clauses.
     * 
     * @param string $relation The relationship name
     * @param Closure|null $callback Callback to add constraints to the relationship query
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereDoesntHave(string $relation, ?Closure $callback = null, string $boolean = 'AND'): QueryBuilder
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    /**
     * Add an OR constraint based on the existence of a relationship with additional where clauses.
     * 
     * @param string $relation The relationship name
     * @param Closure|null $callback Callback to add constraints to the relationship query
     * @param string $operator The operator (>=, <, !=, etc.)
     * @param int $count The count to compare against
     * @return QueryBuilder
     */
    public function orWhereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): QueryBuilder
    {
        return $this->whereHas($relation, $callback, $operator, $count, 'OR');
    }

    /**
     * Add an OR constraint based on the non-existence of a relationship with additional where clauses.
     * 
     * @param string $relation The relationship name
     * @param Closure|null $callback Callback to add constraints to the relationship query
     * @return QueryBuilder
     */
    public function orWhereDoesntHave(string $relation, ?Closure $callback = null): QueryBuilder
    {
        return $this->whereDoesntHave($relation, $callback, 'OR');
    }

    /**
     * Filter results based on relationship field values.
     * 
     * @param string $relation The relationship name
     * @param string $column The column in the related table
     * @param string $operator The comparison operator
     * @param mixed $value The value to compare against
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereRelation(string $relation, string $column, string $operator = '=', $value = null, string $boolean = 'AND'): QueryBuilder
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        return $this->whereHas($relation, fn($query) => $query->where($this->addRelatedTablePrefix($relation, $column), $operator, $value), '>=', 1, $boolean);
    }

    /**
     * Filter results based on relationship field values using OR.
     * 
     * @param string $relation The relationship name
     * @param string $column The column in the related table
     * @param string $operator The comparison operator
     * @param mixed $value The value to compare against
     * @return QueryBuilder
     */
    public function orWhereRelation(string $relation, string $column, string $operator = '=', $value = null): QueryBuilder
    {
        return $this->whereRelation($relation, $column, $operator, $value, 'OR');
    }

    /**
     * Filter results where relationship field is in given values.
     * 
     * @param string $relation The relationship name
     * @param string $column The column in the related table
     * @param array $values The values array
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereRelationIn(string $relation, string $column, array $values, string $boolean = 'AND'): QueryBuilder
    {
        return $this->whereHas($relation, fn($query) => $query->whereIn($this->addRelatedTablePrefix($relation, $column), $values), '>=', 1, $boolean);
    }

    /**
     * Filter results where relationship field is not in given values.
     * 
     * @param string $relation The relationship name
     * @param string $column The column in the related table
     * @param array $values The values array
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereRelationNotIn(string $relation, string $column, array $values, string $boolean = 'AND'): QueryBuilder
    {
        return $this->whereHas($relation, fn($query) => $query->whereNotIn($this->addRelatedTablePrefix($relation, $column), $values), '>=', 1, $boolean);
    }

    /**
     * Filter results where relationship field is null.
     * 
     * @param string $relation The relationship name
     * @param string $column The column in the related table
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereRelationNull(string $relation, string $column, string $boolean = 'AND'): QueryBuilder
    {
        return $this->whereHas($relation, fn($query) => $query->whereNull($this->addRelatedTablePrefix($relation, $column)), '>=', 1, $boolean);
    }

    /**
     * Filter results where relationship field is not null.
     * 
     * @param string $relation The relationship name
     * @param string $column The column in the related table
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereRelationNotNull(string $relation, string $column, string $boolean = 'AND'): QueryBuilder
    {
        return $this->whereHas($relation, fn($query) => $query->whereNotNull($this->addRelatedTablePrefix($relation, $column)), '>=', 1, $boolean);
    }

    /**
     * Filter results where relationship field matches a pattern.
     * 
     * @param string $relation The relationship name
     * @param string $column The column in the related table
     * @param string $pattern The pattern to match
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereRelationLike(string $relation, string $column, string $pattern, string $boolean = 'AND'): QueryBuilder
    {
        return $this->whereHas($relation, fn($query) => $query->like($this->addRelatedTablePrefix($relation, $column), $pattern), '>=', 1, $boolean);
    }

    /**
     * Filter results where relationship field is between two values.
     * 
     * @param string $relation The relationship name
     * @param string $column The column in the related table
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereRelationBetween(string $relation, string $column, $min, $max, string $boolean = 'AND'): QueryBuilder
    {
        return $this->whereHas($relation, fn($query) => $query->between($this->addRelatedTablePrefix($relation, $column), $min, $max), '>=', 1, $boolean);
    }

    /**
     * Filter results where relationship field has a value in a set.
     * 
     * @param string $relation The relationship name
     * @param string $column The column in the related table
     * @param mixed $value The value to find in the set
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereRelationFindInSet(string $relation, string $column, $value, string $boolean = 'AND'): QueryBuilder
    {
        return $this->whereHas($relation, fn($query) => $query->findInSet($this->addRelatedTablePrefix($relation, $column), $value), '>=', 1, $boolean);
    }

    /**
     * Filter results where relationship JSON field contains a value.
     * 
     * @param string $relation The relationship name
     * @param string $column The JSON column in the related table
     * @param string $key The key in the JSON object
     * @param mixed $value The value to find
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function whereRelationJson(string $relation, string $column, string $key, $value, string $boolean = 'AND'): QueryBuilder
    {
        return $this->whereHas($relation, fn($query) => $query->findInJson($this->addRelatedTablePrefix($relation, $column), $key, $value), '>=', 1, $boolean);
    }

    /**
     * Add OR versions of the relationship filter methods.
     */
    public function orWhereRelationIn(string $relation, string $column, array $values): QueryBuilder
    {
        return $this->whereRelationIn($relation, $column, $values, 'OR');
    }

    public function orWhereRelationNotIn(string $relation, string $column, array $values): QueryBuilder
    {
        return $this->whereRelationNotIn($relation, $column, $values, 'OR');
    }

    public function orWhereRelationNull(string $relation, string $column): QueryBuilder
    {
        return $this->whereRelationNull($relation, $column, 'OR');
    }

    public function orWhereRelationNotNull(string $relation, string $column): QueryBuilder
    {
        return $this->whereRelationNotNull($relation, $column, 'OR');
    }

    public function orWhereRelationLike(string $relation, string $column, string $pattern): QueryBuilder
    {
        return $this->whereRelationLike($relation, $column, $pattern, 'OR');
    }

    public function orWhereRelationBetween(string $relation, string $column, $min, $max): QueryBuilder
    {
        return $this->whereRelationBetween($relation, $column, $min, $max, 'OR');
    }

    public function orWhereRelationFindInSet(string $relation, string $column, $value): QueryBuilder
    {
        return $this->whereRelationFindInSet($relation, $column, $value, 'OR');
    }

    public function orWhereRelationJson(string $relation, string $column, string $key, $value): QueryBuilder
    {
        return $this->whereRelationJson($relation, $column, $key, $value, 'OR');
    }

    /**
     * Filter results based on relationship count with additional conditions.
     * 
     * @param string $relation The relationship name
     * @param Closure $callback Callback to add constraints to the relationship query
     * @param string $operator The count comparison operator
     * @param int $count The count to compare against
     * @param string $boolean The boolean operator (AND/OR)
     * @return QueryBuilder
     */
    public function withCount(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1, string $boolean = 'AND'): QueryBuilder
    {
        $model = $this->getRelatedModel();
        $relationConfig = $model->getRelationshipConfig($relation);

        // Add a custom field to select the count
        $countAlias = $relation . '_count';

        // Build the subquery for counting
        $this->addCountSubquery($relationConfig, $relation, $countAlias, $callback);

        return $this;
    }

    /**
     * Fetch a model instance with the specified class.
     * 
     * This method fetches a model instance using the specified class name.
     * It returns a QueryBuilder instance with the model data.
     * 
     * @param string $model
     * @return QueryBuilder
     */
    public function fetchModel(string $model): QueryBuilder
    {
        if (is_string($model) && class_exists($model)) {
            return $this->fetch(\PDO::FETCH_CLASS, $model);
        }

        throw new InvalidOrmException("Invalid model class: {$model}");
    }

    /**
     * Use a specific model instance for the query.
     *
     * @param Model $model
     * @return QueryBuilder
     */
    public function useModel(Model $model): QueryBuilder
    {
        $this->query['use_model'] = $model;
        return $this;
    }

    /**
     * Get the related model instance.
     * 
     * This method retrieves the related model instance based on the query configuration.
     * It throws an exception if the model is not a valid instance of Model.
     * 
     * @return Model
     * @throws InvalidOrmException
     */
    private function getRelatedModel(): Model
    {
        $model = $this->query['fetch'][1] ?? null;

        if (is_string($model) && class_exists($model)) {
            $model = new $model;
        }

        if (!$model instanceof Model) {
            throw new InvalidOrmException("The relationship methods must be called on a model instance.");
        }

        return $model;
    }

    /**
     * Check if the QueryBuilder has a related Model.
     *
     * @return bool
     */
    private function hasRelatedModel(): bool
    {
        try {
            $this->getRelatedModel();
            return true;
        } catch (InvalidOrmException $e) {
            return false;
        }
    }

    /**
     * Check if the model has a related instance.
     *
     * @return bool
     */
    private function isUsingModel(): bool
    {
        return isset($this->query['use_model']) && $this->query['use_model'] instanceof Model;
    }

    /**
     * Get the model being used in the query.
     *
     * @return Model|null
     */
    private function getModelBeingUsed(): ?Model
    {
        return $this->isUsingModel() ? $this->query['use_model'] : null;
    }

    /**
     * Apply conditions based on the related model.
     *
     * @return void
     */
    private function applyModelPrimaryCondition(): void
    {
        $model = $this->getModelBeingUsed();
        if (!$model) {
            return;
        }

        $primaryValue = $model->primaryValue();

        if (!empty($primaryValue)) {
            $this->where([$model::$primaryKey => $primaryValue]);
        }
    }

    /**
     * Add constraint based on relationship existence.
     * 
     * @param array $relationConfig The relationship configuration
     * @param string $relation The relationship name
     * @param string $operator The operator (>=, <, !=, etc.)
     * @param int $count The count to compare against
     * @param string $boolean The boolean operator (AND/OR)
     * @param Closure|null $callback Optional callback to add constraints to the relationship query
     * @return QueryBuilder
     */
    private function addHasConstraint(array $relationConfig, string $relation, string $operator, int $count, string $boolean, ?Closure $callback = null): QueryBuilder
    {
        $subquery = $this->buildRelationshipSubquery($relationConfig, $callback);

        $sql = "({$subquery['sql']}) {$operator} {$count}";

        $this->bindings = array_merge($this->bindings, $subquery['bindings']);
        $this->parameters = array_merge($this->parameters, $subquery['parameters']);

        return $this->whereRaw($sql, [], $boolean);
    }

    /**
     * Build a subquery for relationship constraints.
     * 
     * @param array $relationConfig The relationship configuration
     * @param Closure|null $callback Optional callback to add constraints to the relationship query
     * @return array The subquery SQL
     */
    private function buildRelationshipSubquery(array $relationConfig, ?Closure $callback = null): array
    {
        $relatedModel = new $relationConfig['related'];
        $relatedTable = $relatedModel::$table ?? $this->getTableFromClass($relationConfig['related']);

        switch ($relationConfig['type']) {
            case 'hasOne':
            case 'hasMany':
                $query = $relatedModel->query()
                    ->select('COUNT(*)')
                    ->whereRaw(
                        $this->grammar->wrapTable($relatedTable) . "." . $this->grammar->wrapColumn($relationConfig['foreignKey']) . " = " .
                        $this->getTableName() . "." . $this->grammar->wrapColumn($relationConfig['localKey'])
                    );
                break;

            case 'belongsTo':
                $query = $relatedModel->query()
                    ->select('COUNT(*)')
                    ->whereRaw(
                        $this->grammar->wrapTable($relatedTable) . "." . $this->grammar->wrapColumn($relationConfig['ownerKey']) . " = " .
                        $this->getTableName() . "." . $this->grammar->wrapColumn($relationConfig['foreignKey'])
                    );
                break;

            case 'belongsToMany':
                $query = $relatedModel->query()
                    ->select('COUNT(*)')
                    ->join(
                        $relationConfig['table'],
                        $relationConfig['table'] . ".{$relationConfig['relatedPivotKey']}",
                        '=',
                        "{$relatedTable}.{$relationConfig['relatedKey']}"
                    )
                    ->whereRaw(
                        $this->grammar->wrapTable($relationConfig['table']) . "." . $this->grammar->wrapColumn($relationConfig['relatedPivotKey']) . " = " .
                        $this->getTableName() . "." . $this->grammar->wrapColumn($relationConfig['parentKey'])
                    );
                break;

            case 'hasManyThrough':
            case 'hasOneThrough':
                $throughModel = new $relationConfig['through'];
                $throughTable = $throughModel::$table ?? $this->getTableFromClass($relationConfig['through']);

                $query = $relatedModel->query()
                    ->select('COUNT(*)')
                    ->join(
                        $throughTable,
                        $throughTable . ".{$relationConfig['secondLocalKey']}",
                        '=',
                        $relatedTable . ".{$relationConfig['secondKey']}"
                    )
                    ->whereRaw(
                        $this->grammar->wrapTable($throughTable) . "." . $this->grammar->wrapColumn($relationConfig['firstKey']) . " = " .
                        $this->getTableName() . "." . $this->grammar->wrapColumn($relationConfig['localKey'])
                    );
                break;

            default:
                throw new InvalidOrmException("Unsupported relationship type: {$relationConfig['type']}");
        }

        if ($callback) {
            $callback($query);
        }

        // Get the built SQL from the query
        return $this->getSubquerySQL($query);
    }

    /**
     * Add the related table prefix to a column name.
     *
     * @param string $relation The relationship name
     * @param string $column The column name
     * @return string The column name with the related table prefix
     */
    private function addRelatedTablePrefix(string $relation, string $column): string
    {
        $model = $this->getRelatedModel();
        $relationConfig = $model->getRelationshipConfig($relation);

        $relatedModel = new $relationConfig['related'];
        $relatedTable = $relatedModel::$table ?? $this->getTableFromClass($relationConfig['related']);

        // Add the related table prefix to the column
        return "$relatedTable.$column";
    }

    /**
     * Add a count subquery to the main query.
     * 
     * @param array $relationConfig The relationship configuration
     * @param string $relation The relationship name
     * @param string $alias The alias for the count column
     * @param Closure|null $callback Optional callback to add constraints to the relationship query
     * @return void
     */
    private function addCountSubquery(array $relationConfig, string $relation, string $alias, ?Closure $callback = null): void
    {
        $subquery = $this->buildRelationshipSubquery($relationConfig, $callback);

        // Modify the select to include the count subquery
        $currentSelect = $this->query['select'] ?: '*';
        $this->query['select'] = $currentSelect . ", ({$subquery['sql']}) as {$alias}";

        $this->bindings = array_merge($this->bindings, $subquery['bindings']);
        $this->parameters = array_merge($this->parameters, $subquery['parameters']);
    }

    /**
     * Extract SQL from query builder for subquery use.
     * 
     * @param QueryBuilder $query
     * @return array
     */
    private function getSubquerySQL(QueryBuilder $query): array
    {
        // This is a simplified approach - in a real implementation, 
        // you might need to build the SQL string manually or extend QueryBuilder
        // to expose its internal SQL building methods

        $table = $query->getTableName();
        $select = $query->query['select'] ?: 'COUNT(*)';
        $joins = $query->query['joins'] ?? '';
        $where = $query->getWhereSql();

        return [
            'sql' => "SELECT {$select} FROM {$table}{$joins}{$where}",
            'bindings' => $query->getBindings(),
            'parameters' => $query->getParameters()
        ];
    }

    /**
     * Get table name from class name.
     * 
     * @param string $class
     * @return string
     */
    private function getTableFromClass(string $class): string
    {
        // This should match the logic in your Model class
        return $class::$table ?? Str::snake(Str::plural(class_basename($class)));
    }

    /**
     * Parse nested relationships from with() arguments.
     * 
     * Converts Laravel-style dot notation to nested structure:
     * ['posts.comments.author'] => ['posts' => ['nested' => ['comments.author']]]
     * 
     * @param array $relations
     * @return array
     */
    private function parseWithRelations(array $relations): array
    {
        $parsed = [];

        foreach ($relations as $name => $constraints) {
            // Handle numeric keys (simple string relations)
            if (is_numeric($name)) {
                $name = $constraints;
                $constraints = null;
            }

            // Check for nested relationships (dot notation)
            if (str_contains($name, '.')) {
                $parts = explode('.', $name, 2);
                $parentRelation = $parts[0];
                $nestedRelation = $parts[1];

                if (!isset($parsed[$parentRelation])) {
                    $parsed[$parentRelation] = ['constraints' => null, 'nested' => []];
                }

                // Avoid duplicates in nested array
                if (!in_array($nestedRelation, $parsed[$parentRelation]['nested'])) {
                    $parsed[$parentRelation]['nested'][] = $nestedRelation;
                }
            } else {
                if (!isset($parsed[$name])) {
                    $parsed[$name] = ['constraints' => null, 'nested' => []];
                }

                // Set constraints if provided (don't override if already set)
                if ($constraints instanceof Closure && $parsed[$name]['constraints'] === null) {
                    $parsed[$name]['constraints'] = $constraints;
                }
            }
        }

        return $parsed;
    }
}
