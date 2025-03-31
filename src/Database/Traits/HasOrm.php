<?php

namespace Spark\Database\Traits;

use Spark\Database\QueryBuilder;
use PDO;
use RuntimeException;

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
     * The ORM configuration for the current model.
     * 
     * This should return an array with the following structure:
     * 
     * [
     *     'relationshipName' => [
     *         'has' => 'one|many|many-x',
     *         'lazy' => Enable lazy loading (default: true),
     *         'model' => Fully Qualified Model Name,
     *         'foreignKey' => 'foreign_key',
     *         'localKey' => 'local_key',
     *         'table' => 'pivate_table' (optional, only required for many-x (one-to-many) relationships),
     *         'callback' => 'callback_function' (optional, to apply custom logic to the query object),
     *     ]
     * ]
     * 
     * @return array The ORM configuration for the current model.
     */
    abstract protected function orm(): array;

    /**
     * Allows for eager loading of related ORM data by specifying the relationships to load.
     * 
     * @param array|string $orm The relationships to load, or '*' to load all configured relationships.
     * @param array $data The data to use for loading the initial model instance.
     * @return QueryBuilder The query object with attached mappers for handling the related data.
     * 
     * @throws RuntimeException If the specified relationship is not defined in the ORM configuration.
     */
    public static function with(array|string $orm = '*', array $data = []): QueryBuilder
    {
        $model = static::load($data);
        $query = $model->get();
        $registeredOrm = $model->orm();

        if ($orm === '*') {
            $orm = array_keys($registeredOrm);
        }

        foreach ((array) $orm as $with) {
            $config = $registeredOrm[$with] ?? false;

            if (!$config) {
                throw new RuntimeException("Orm({$with}) does not specified in: " . $model::class);
            }

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
     * @throws RuntimeException If the specified relationship is not defined in the ORM configuration.
     */
    public static function runOrm(array|string $orm = '*', array &$models = []): void
    {
        if (empty($models)) {
            return;
        }

        $model = new self;
        $registeredOrm = $model->orm();

        if ($orm === '*') {
            $orm = array_keys($registeredOrm);
        }

        foreach ((array) $orm as $with) {
            $config = $registeredOrm[$with] ?? false;

            if (!$config) {
                throw new RuntimeException("Orm({$with}) does not specified in: " . $model::class);
            }

            $model->handleOrm($models, $config, $with);
        }
    }

    /**
     * Retrieve the registered ORM configurations for the model.
     * 
     * @return array
     */
    public function getRegisteredOrm(): array
    {
        return $this->orm();
    }

    /**
     * Magic getter method to load and return ORM data on demand (lazy loading).
     * 
     * @param string $name The name of the ORM relationship to load.
     * @return mixed|null The related data if available, or null if not found.
     * 
     * @throws RuntimeException If lazy loading is disabled for the requested relationship.
     */
    protected function getFromOrm($name)
    {
        if (isset($this->orm[$name])) {
            return $this->orm[$name];
        }

        // load lazy orm data
        $config = $this->orm()[$name] ?? false;
        if ($config) {
            if (isset($config['lazy']) && !$config['lazy']) {
                throw new RuntimeException("Lazy load has been disabled for {$name}, " . static::class);
            }

            $this->handleOrm([$this], $config, $name);
            return $this->orm[$name];
        }

        return null;
    }

    /**
     * Check if the specified ORM relationship is set.
     * 
     * @param string $name The name of the ORM relationship to check.
     * @return bool True if the relationship has been set, false otherwise.
     */
    protected function existsInOrm($name)
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
    protected function removeFromOrm($name)
    {
        // Remove the ORM relationship from the data set.
        unset($this->orm[$name]);
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
     * @throws RuntimeException If an invalid ORM type is specified.
     */
    private function handleOrm(array $data, array $config, string $with): array
    {
        return match ($config['has']) {
            'many-x' => $this->manyX($data, $config, $with),
            'many' => $this->many($data, $config, $with),
            'one' => $this->one($data, $config, $with),
            default => throw new RuntimeException("Invalid Orm Type({$config['has']})")
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
            throw new RuntimeException("No intermediate table specified for Orm({$with})");
        }

        $primaryKey = static::$primaryKey;

        $model = new $config['model'];
        $ids = collect($data)
            ->pluck($primaryKey)
            ->unique();

        // get or genarate foreign for forriegn model
        $foreignKey = $config['foreignKey'] ?? $model::$table . '_id';

        // get or genarate local for currennt/loccal model
        $localKey = $config['localKey'] ?? static::$table . '_id';

        // get objects from intermediate table
        $objects = $ids->count() > 0 ? $this->applyCallback(
            $model->query()
                ->fetch(PDO::FETCH_ASSOC)
                ->select("p.*, t1.{$foreignKey}, t1.{$localKey}")
                ->join($config['table'], "t1.{$foreignKey} = p.{$primaryKey}")
                ->where([
                    "t1.{$localKey}" => $ids->all()
                ]),
            $config
        )->result() : [];

        return $this->parseOrmData($data, $objects, $model, $with);
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
        $localKey = $config['localKey'] ?? static::$table . '_id';

        // get objects from foreign table
        $objects = $ids->count() > 0 ? $this->applyCallback(
            $model->query()
                ->select()
                ->fetch(PDO::FETCH_ASSOC)
                ->where([
                    $localKey => $ids->all()
                ]),
            $config
        )->result() : [];

        return $this->parseOrmData($data, $objects, $model, $with);
    }

    /**
     * Parses and attaches related ORM data to the main data set.
     * 
     * @param array $data The main data set.
     * @param array $objects The related objects fetched based on the ORM configuration.
     * @param object $model The related model object.
     * @param string $with The name of the relationship.
     * @return array The data with attached ORM data.
     */
    private function parseOrmData(array $data, $objects, $model, $with): array
    {
        // get or genarate localkey for currennt/loccal model
        $localKey = $config['localKey'] ?? static::$table . '_id';

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
        $foreignKey = $config['foreignKey'] ?? $model::$table . '_id';

        // get or genarate local for currennt/loccal model
        $localKey = $config['localKey'] ?? static::$primaryKey;

        // get ids from main data
        $ids = collect($data)
            ->pluck($foreignKey)
            ->unique();

        // get objects from foreign table
        $objects = $ids->count() > 0 ? $this->applyCallback(
            $model->query()
                ->select()
                ->fetch(PDO::FETCH_ASSOC)
                ->where([$localKey => $ids->all()]),
            $config
        )
            ->result() : [];

        // attach related data
        foreach ($data as $d) {
            if (!isset($d->orm[$with])) {
                $d->orm[$with] = false;
            }

            foreach ($objects as $o) {
                if ($o[$localKey] === $d->{$foreignKey}) {
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
     * Validates and handles form submissions for many-x relationships, 
     * synchronizing the intermediate table records.
     * 
     * @return bool True if validation and synchronization were successful, false otherwise.
     */
    protected function checkOrmFormFields(): bool
    {
        $status = false;
        foreach ($this->orm() as $config) {
            if (
                $config['has'] !== 'many-x' ||
                !isset($config['table']) ||
                request()->post($config['table'], false) === false
            ) {
                continue;
            }

            $old_ids = array_filter(
                array_map('trim', explode(',', request()->post("_{$config['table']}", '')))
            );
            $new_ids = request()->post($config['table'], []);
            $new_ids = array_filter(
                is_string($new_ids) ? array_filter(
                    array_map('trim', explode(',', $new_ids))
                ) : $new_ids
            );

            $remove_ids = array_diff($old_ids, $new_ids);
            $create_ids = array_diff($new_ids, $old_ids);

            $model = new $config['model'];
            $query = query($config['table']);

            // get or genarate foreignkey and localkey
            $foreignKey = $config['foreignKey'] ?? $model::$table . '_id';
            $localKey = $config['localKey'] ?? static::$table . '_id';

            if (!empty($create_ids)) {
                $ids = collect($create_ids)
                    ->map(fn($id) => [
                        $foreignKey => $id,
                        $localKey => $this->primaryValue(),
                    ])
                    ->all();
                $query->insert(array_values($ids));
                $status = true;
            }

            if (!empty($remove_ids)) {
                $query->delete([$localKey => $this->primaryValue(), $foreignKey => $remove_ids]);
                $status = true;
            }
        }

        return $status;
    }
}
