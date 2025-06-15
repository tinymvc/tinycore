<?php

namespace Spark\Database\Traits;

use Closure;
use Spark\Database\Exceptions\InvalidOrmException;
use Spark\Database\Exceptions\OrmDisabledLazyLoadingException;
use Spark\Database\Exceptions\UndefinedOrmException;
use Spark\Database\QueryBuilder;
use PDO;
use Spark\Support\Str;

/**
 * ORM - Object Relational Mapping
 * 
 * Provides functionality for handling object-relational mapping (ORM) in a tinymvc model.
 * It includes methods for managing relationships between models, such as one-to-one, one-to-many,
 * and many-to-many, with support for lazy loading and eager loading of related data.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
trait HasOrm
{
    /**
     * @var array $orm
     * Holds the loaded ORM data for the current instance.
     */
    private array $orm;


    /**
     * Initializes the ORM data for the model.
     * 
     * This method is called when the model is instantiated to ensure that the ORM data
     * is ready for use. It can be overridden in child classes to customize ORM initialization.
     */
    protected function getOrm(): array
    {
        return $this->orm ?? [];
    }

    /**
     * Defines a one-to-one relationship.
     * 
     * @param string $model The related model class name.
     * @param string|null $foreignKey The foreign key in the related model (optional).
     * @param string|null $localKey The local key in the current model (optional).
     * @param bool $lazy Whether to enable lazy loading (default: true).
     * @param Closure|null $callback A callback function to apply custom logic to the query object (optional).
     * 
     * @return array The ORM configuration for the one-to-one relationship.
     */
    protected function hasOne(
        string $model,
        ?string $foreignKey = null,
        ?string $localKey = null,
        bool $lazy = true,
        ?Closure $callback = null
    ): array {
        return [
            'has' => 'one',
            'lazy' => $lazy,
            'model' => $model,
            'foreignKey' => $foreignKey,
            'localKey' => $localKey,
            'callback' => $callback,
        ];
    }

    /**
     * Defines a one-to-many relationship.
     * 
     * @param string $model The related model class name.
     * @param string|null $foreignKey The foreign key in the related model (optional).
     * @param string|null $localKey The local key in the current model (optional).
     * @param bool $lazy Whether to enable lazy loading (default: true).
     * @param Closure|null $callback A callback function to apply custom logic to the query object (optional).
     * 
     * @return array The ORM configuration for the one-to-many relationship.
     */
    protected function hasMany(
        string $model,
        ?string $foreignKey = null,
        ?string $localKey = null,
        bool $lazy = true,
        ?Closure $callback = null
    ): array {
        return [
            'has' => 'many',
            'lazy' => $lazy,
            'model' => $model,
            'foreignKey' => $foreignKey,
            'localKey' => $localKey,
            'callback' => $callback,
        ];
    }

    /**
     * Defines a many-to-many relationship using an intermediate table.
     * 
     * @param string $model The related model class name.
     * @param string|null $table The name of the pivot/intermediate table.
     * @param string|null $foreignKey The foreign key in the related model (optional).
     * @param string|null $localKey The local key in the current model (optional).
     * @param bool $lazy Whether to enable lazy loading (default: true).
     * @param Closure|null $callback A callback function to apply custom logic to the query object (optional).
     * 
     * @return array The ORM configuration for the many-to-many relationship.
     */
    protected function hasManyX(
        string $model,
        ?string $table = null,
        ?string $foreignKey = null,
        ?string $localKey = null,
        bool $lazy = true,
        ?Closure $callback = null
    ): array {
        return [
            'has' => 'many-x',
            'lazy' => $lazy,
            'model' => $model,
            'foreignKey' => $foreignKey,
            'localKey' => $localKey,
            'table' => $table,
            'callback' => $callback,
        ];
    }

    /**
     * Allows for eager loading of related ORM data by specifying the relationships to load.
     * 
     * @param array|string $orm The relationships to load, or '*' to load all configured relationships.
     * @return QueryBuilder The query object with attached mappers for handling the related data.
     * 
     * @throws UndefinedOrmException If the specified relationship is not defined in the ORM configuration.
     */
    public static function with(array|string $orm): QueryBuilder
    {
        $model = new static;
        $registeredOrm = $model->getRegisteredRelations($orm);
        $query = $model->query();

        foreach ($registeredOrm as $with => $config) {
            $query->addMapper(fn($data) => $model->handleOrm($data, $config, $with));
        }

        return $query;
    }

    /**
     * Runs the specified ORM relationship on the given set of models.
     * 
     * If no ORM relationships are specified, all registered relationships will be loaded.
     * 
     * @param array|string $orm The relationships to load, or '*' to load all registered relationships.
     * @param array $models The set of models to apply the ORM to.
     * 
     * @return void
     * 
     * @throws UndefinedOrmException If the specified relationship is not defined in the ORM configuration.
     */
    public static function runOrm(array|string $orm, array &$models = []): void
    {
        if (empty($models)) {
            return;
        }

        $model = new static;
        $registeredOrm = $model->getRegisteredRelations($orm);

        foreach ($registeredOrm as $with => $config) {
            $model->handleOrm($models, $config, $with);
        }
    }

    /**
     * Magic getter method to load and return ORM data on demand (lazy loading).
     * 
     * @param string $name The name of the ORM relationship to load.
     * @return mixed|null The related data if available, or null if not found.
     * 
     * @throws OrmDisabledLazyLoadingException If lazy loading is disabled for the requested relationship.
     */
    protected function getFromOrm($name)
    {
        if (isset($this->orm[$name])) {
            return $this->orm[$name];
        }

        // load lazy orm data
        $config = $this->getRegisteredRelations($name, true);
        if ($config) {
            if (isset($config['lazy']) && !$config['lazy']) {
                throw new OrmDisabledLazyLoadingException("Lazy load has been disabled for {$name}, " . static::class);
            }

            $this->handleOrm([$this], $config, $name); // load the data

            return $this->orm[$name]; // return the loaded data
        }

        return null;
    }

    /**
     * Check if the specified ORM relationship is set.
     * 
     * @param string $name The name of the ORM relationship to check.
     * @return bool True if the relationship has been set, false otherwise.
     */
    protected function existsInOrm($name): bool
    {
        // If the relationship is not set, return false.
        // Otherwise, return true if the relationship is not empty.
        return $this->getFromOrm($name) !== null && !empty($this->getFromOrm($name));
    }

    /**
     * Magic unset method to remove ORM data when it is unset.
     * 
     * @param string $name The name of the ORM relationship to remove.
     * 
     * @return void
     */
    protected function removeFromOrm($name): void
    {
        // Remove the ORM relationship from the data set.
        unset($this->orm[$name]);
    }

    /**
     * Retrieves the registered ORM relationships for the current model.
     * 
     * @param array|string $orm The relationships to retrieve, or '*' to retrieve all registered relationships.
     * @param bool $single Whether to return a single relationship configuration (default: false).
     * 
     * @return array The registered ORM relationships.
     */
    private function getRegisteredRelations(array|string $orm, bool $single = false)
    {
        $ormConfigs = [];
        foreach ((array) $orm as $with) {
            // Check if the relationship is set to load all relationships.
            if (!method_exists($this, $with)) {
                continue;
            }

            $ormConfigs[$with] = $this->{$with}();
        }

        return $single ? $ormConfigs[array_key_first($ormConfigs)] ?? null : $ormConfigs;
    }

    /**
     * Handles loading of ORM data based on the specified configuration.
     * Supports different relationship types: 'one', 'many', and 'many-x'.
     * 
     * @param array $data The main data set to attach related data to.
     * @param array $config The configuration for the ORM relationship.
     * @param string $with The name of the relationship to process.
     * @return array The data with attached ORM relationships.
     * 
     * @throws InvalidOrmException If an invalid ORM type is specified.
     */
    private function handleOrm(array $data, array $config, string $with): array
    {
        return match ($config['has']) {
            'many-x' => $this->manyX($data, $config, $with),
            'many' => $this->many($data, $config, $with),
            'one' => $this->one($data, $config, $with),
            default => throw new InvalidOrmException("Invalid Orm Type({$config['has']})")
        };
    }

    /**
     * Handles many-to-many relationships where an intermediate table is used.
     * 
     * @param array $data The main data set.
     * @param array $config The configuration for the many-x relationship.
     * @param string $with The name of the relationship.
     * @return array The data with the many-x related data attached.
     */
    private function manyX(array $data, array $config, string $with): array
    {
        if (!isset($config['table'])) {
            throw new InvalidOrmException("No intermediate/pivot table specified for Orm({$with})");
        }

        $primaryKey = static::$primaryKey;

        $model = new $config['model'];
        $ids = collect($data)
            ->pluck($primaryKey)
            ->unique();

        // get or genarate foreign for forriegn model
        $foreignKey = $config['foreignKey'] ?? $this->generateForeignKey($model::$table);

        // get or genarate local for currennt/loccal model
        $localKey = $config['localKey'] ?? $this->generateForeignKey(static::$table);

        // get objects from intermediate table
        $objects = $ids->count() > 0 ? $this->applyCallback(
            $model->query()
                ->fetch(PDO::FETCH_ASSOC)
                ->select("p.*, t1.{$foreignKey}, t1.{$localKey}")
                ->as('p')
                ->join($config['table'] . ' as t1', "t1.{$foreignKey} = p.{$primaryKey}")
                ->where([
                    "t1.{$localKey}" => $ids->all()
                ]),
            $config
        )->all() : [];

        return $this->parseOrmData($data, $objects, $model, $with, $localKey);
    }

    /**
     * Handles one-to-many relationships.
     * 
     * @param array $data The main data set.
     * @param array $config The configuration for the many relationship.
     * @param string $with The name of the relationship.
     * @return array The data with the many related data attached.
     */
    private function many(array $data, array $config, string $with): array
    {
        $model = new $config['model'];
        $ids = collect($data)
            ->pluck(static::$primaryKey)
            ->unique();

        // get or genarate local for currennt/loccal model
        $localKey = $config['localKey'] ?? $this->generateForeignKey(static::$table);

        // get objects from foreign table
        $objects = $ids->count() > 0 ? $this->applyCallback(
            $model->query()
                ->select()
                ->fetch(PDO::FETCH_ASSOC)
                ->where([
                    $localKey => $ids->all()
                ]),
            $config
        )->all() : [];

        return $this->parseOrmData($data, $objects, $model, $with, $localKey);
    }

    /**
     * Parses and attaches related ORM data to the main data set.
     * 
     * @param array $data The main data set.
     * @param array $objects The related objects fetched based on the ORM configuration.
     * @param object $model The related model object.
     * @param string $with The name of the relationship.
     * @param string $localKey The local key for the current model.
     * @return array The data with attached ORM data.
     */
    private function parseOrmData(array $data, $objects, $model, $with, $localKey): array
    {
        // attach related data
        foreach ($data as $d) {
            if (!isset($d->orm[$with])) {
                $d->orm[$with] = [];
            }

            foreach ($objects as $o) {
                if ($o[$localKey] === $d->{static::$primaryKey}) {
                    $d->orm[$with][] = $model->load($o);
                }
            }
        }

        return $data;
    }

    /**
     * Handles one-to-one relationships.
     * 
     * @param array $data The main data set.
     * @param array $config The configuration for the one relationship.
     * @param string $with The name of the relationship.
     * @return array The data with the one related data attached.
     */
    private function one(array $data, array $config, string $with): array
    {
        $model = new $config['model'];

        // get or genarate foreign for forriegn model
        $foreignKey = $config['foreignKey'] ?? $this->generateForeignKey($model::$table);

        // get ids from main data
        $ids = collect($data)
            ->pluck($foreignKey)
            ->unique();

        // get objects from foreign table
        $objects = $ids->count() > 0 ? $this->applyCallback(
            $model->query()
                ->select()
                ->fetch(PDO::FETCH_ASSOC)
                ->where([static::$primaryKey => $ids->all()]),
            $config
        )
            ->all() : [];

        // attach related data
        foreach ($data as $d) {
            if (!isset($d->orm[$with])) {
                $d->orm[$with] = false;
            }

            foreach ($objects as $o) {
                if ($o[static::$primaryKey] === $d->{$foreignKey}) {
                    $d->orm[$with] = $model->load($o);
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * Applies a callback to a query object, if provided in the configuration.
     * The callback should accept a query object as its first argument.
     * 
     * @param QueryBuilder $query The query object.
     * @param array $config The ORM relationship configuration.
     * @return QueryBuilder The modified query object.
     */
    private function applyCallback(QueryBuilder $query, array $config): QueryBuilder
    {
        if (isset($config['callback']) && is_callable($config['callback'])) {
            $query = call_user_func($config['callback'], $query);
        }

        return $query;
    }

    /**
     * Generates a foreign key column name based on a table name.
     *
     * @param string $tableOrColumn The name of the table.
     * @return string The generated foreign key column name.
     */
    private function generateForeignKey(string $tableOrColumn): string
    {
        return Str::lower(
            Str::singular(Str::beforeLast($tableOrColumn, '_id'))
        ) . '_id';
    }
}
