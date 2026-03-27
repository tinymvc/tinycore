<?php

namespace Spark\Database\Relation;

use Closure;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Traits\HasPivotTableForRelation;
use function is_array;

/**
 * Class BelongsToMany
 * 
 * Represents a "belongs to many" relationship in a database model.
 * This class is used to define a many-to-many relationship
 * between two models, where a model can belong to multiple instances
 * of another model and vice versa.
 * 
 * @package Spark\Database\Relation
 */
class BelongsToMany extends Relation
{
    use HasPivotTableForRelation;

    /**
     * Create a new BelongsToMany relationship instance.
     * 
     * @param string $related The related model class name.
     * @param string|null $table The pivot table name that holds the relationship.
     * @param string|null $foreignPivotKey The foreign key in the pivot table that references the current model.
     * @param string|null $relatedPivotKey The foreign key in the pivot table that references the related model.
     * @param string|null $parentKey The primary key in the current model that the foreign pivot key references.
     * @param string|null $relatedKey The primary key in the related model that the related pivot key references.
     * @param bool $lazy Whether to load the relationship lazily.
     * @param Closure|null $callback An optional callback to modify the query for the relationship.
     * @param Model|null $model The model instance that this relationship belongs to.
     */
    public function __construct(
        protected string $related,
        protected null|string $table = null,
        protected null|string $foreignPivotKey = null,
        protected null|string $relatedPivotKey = null,
        protected null|string $parentKey = null,
        protected null|string $relatedKey = null,
        protected bool $lazy = true,
        protected null|Closure $callback = null,
        null|Model $model = null,
    ) {
        parent::__construct($model);
    }

    /**
     * Build the query for this relationship.
     * 
     * @return QueryBuilder
     */
    protected function buildQuery(): QueryBuilder
    {
        /** @var Model $relatedInstance */
        $relatedInstance = new ($this->related)();
        $query = $relatedInstance::query();

        // Join the pivot table
        $query->join(
            $this->table,
            $relatedInstance->getTable() . '.' . $this->relatedKey,
            '=',
            $this->table . '.' . $this->relatedPivotKey
        );

        // Add relationship constraint
        if ($this->model) {
            $parentKeyValue = $this->model->{$this->parentKey};
            $query->where($this->table . '.' . $this->foreignPivotKey, '=', $parentKeyValue);
        }

        // Apply custom callback if provided
        if ($this->callback) {
            ($this->callback)($query);
        }

        return $query;
    }

    /**
     * Get the configuration for the BelongsToMany relationship.
     * 
     * @return array{
     *     related: string,
     *     table: string|null,
     *     foreignPivotKey: string|null,
     *     relatedPivotKey: string|null,
     *     parentKey: string|null,
     *     relatedKey: string|null,
     *     pivotFields: null|string,
     *     wherePivot: array,
     *     lazy: bool,
     *     callback: Closure|null
     * }
     */
    public function getConfig(): array
    {
        return [
            'related' => $this->related,
            'table' => $this->table,
            'foreignPivotKey' => $this->foreignPivotKey,
            'relatedPivotKey' => $this->relatedPivotKey,
            'parentKey' => $this->parentKey,
            'relatedKey' => $this->relatedKey,
            'pivotFields' => $this->buildPivotFields(),
            'wherePivot' => $this->buildPivotConditions(),
            'lazy' => $this->lazy,
            'callback' => $this->callback,
        ];
    }

    /**
     * Attach a model to the parent via the pivot table.
     * 
     * @param int|array $ids The ID(s) of the model(s) to attach. e.x, [4 => ['extra' => 'value'], 5 => []] or [4, 5]
     * @param array $attributes Additional pivot table attributes. e.x, ['created_at' => now()]
     * @return void
     */
    public function attach(int|array $ids, array $attributes = []): void
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot attach without a parent model instance.');
        }

        $parentKeyValue = $parent->{$this->parentKey};

        if (empty($parentKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->parentKey} must be set before attaching related models.");
        }

        $records = [];

        // Normalize the input
        if (!is_array($ids)) {
            $ids = [$ids => []];
        } elseif (array_is_list($ids)) {
            // If it's a sequential array, convert to associative with empty attributes
            $ids = array_fill_keys($ids, []);
        }

        foreach ($ids as $relatedId => $attrs) {
            $records[] = [
                ...$attributes,
                ...$attrs,
                $this->foreignPivotKey => $parentKeyValue,
                $this->relatedPivotKey => $relatedId
            ];
        }

        if (!empty($records)) {
            /** @var \Spark\Database\QueryBuilder to insert into pivot table */
            $query = app(QueryBuilder::class);
            $query->table($this->table)->insert($records);
        }
    }

    /**
     * Detach models from the parent via the pivot table.
     * 
     * @param null|int|array $ids The ID(s) of the model(s) to detach. If null, detach all.
     * @return int The number of rows affected.
     */
    public function detach(null|int|array $ids = null): int
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot detach without a parent model instance.');
        }

        $parentKeyValue = $parent->{$this->parentKey};

        if (empty($parentKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->parentKey} must be set before detaching related models.");
        }

        /** @var \Spark\Database\QueryBuilder to insert into pivot table */
        $query = app(QueryBuilder::class);
        $query->table($this->table)
            ->where($this->foreignPivotKey, $parentKeyValue);

        if ($ids !== null) {
            $query->whereIn($this->relatedPivotKey, (array) $ids);
        }

        return $query->delete();
    }

    /**
     * Sync the intermediate table with a list of IDs.
     * 
     * @param int|array $ids The IDs to sync.
     * @param bool $detaching Whether to detach missing models.
     * @param array $attributes Additional pivot table attributes for attached models.
     * @return array Array with 'attached', and 'detached' keys.
     */
    public function sync(int|array $ids, bool $detaching = true, array $attributes = []): array
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot sync without a parent model instance.');
        }

        $parentKeyValue = $parent->{$this->parentKey};

        if (empty($parentKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->parentKey} must be set before syncing related models.");
        }

        $changes = ['attached' => [], 'detached' => []];

        // Normalize the input
        if (!is_array($ids)) {
            $ids = [$ids => []];
        } elseif (array_is_list($ids)) {
            // If it's a sequential array, convert to associative with empty attributes
            $ids = array_fill_keys($ids, []);
        }

        /** @var \Spark\Database\QueryBuilder to insert into pivot table */
        $query = app(QueryBuilder::class);

        // Get currently attached IDs
        $currentIds = $query->table($this->table)
            ->where($this->foreignPivotKey, $parentKeyValue)
            ->select($this->relatedPivotKey)
            ->fetchColumn()
            ->map('intval')
            ->all();

        $syncIds = array_keys($ids);

        // Detach models that are no longer in the list
        if ($detaching) {
            $detach = array_diff($currentIds, $syncIds);
            if (!empty($detach)) {
                $this->detach($detach);
                $changes['detached'] = array_values($detach);
            }
        }

        // Attach new models
        $attach = array_diff($syncIds, $currentIds);
        if (!empty($attach)) {
            $this->attach(
                array_intersect_key($ids, array_flip($attach)),
                $attributes
            );
            $changes['attached'] = array_values($attach);
        }

        return $changes;
    }

    /**
     * Toggle the attachment of models to the parent.
     * 
     * @param int|array $ids The ID(s) to toggle.
     * @param array $attributes Additional pivot table attributes.
     * @return array Array with 'attached' and 'detached' keys.
     */
    public function toggle(int|array $ids, array $attributes = []): array
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot toggle without a parent model instance.');
        }

        $parentKeyValue = $parent->{$this->parentKey};

        if (empty($parentKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->parentKey} must be set before toggling related models.");
        }

        $changes = ['attached' => [], 'detached' => []];

        // Normalize the input
        if (!is_array($ids)) {
            $ids = [$ids => []];
        } elseif (array_is_list($ids)) {
            // If it's a sequential array, convert to associative with empty attributes
            $ids = array_fill_keys($ids, []);
        }

        /** @var \Spark\Database\QueryBuilder to insert into pivot table */
        $query = app(QueryBuilder::class);

        $syncIds = array_keys($ids);

        // Get currently attached IDs
        $currentIds = $query->table($this->table)
            ->where($this->foreignPivotKey, $parentKeyValue)
            ->whereIn($this->relatedPivotKey, $syncIds)
            ->select($this->relatedPivotKey)
            ->fetchColumn()
            ->map('intval')
            ->all();

        // Detach currently attached
        $detach = array_intersect($syncIds, $currentIds);
        if (!empty($detach)) {
            $this->detach($detach);
            $changes['detached'] = array_values($detach);
        }

        // Attach currently detached
        $attach = array_diff($syncIds, $currentIds);
        if (!empty($attach)) {
            $this->attach(
                array_intersect_key($ids, array_flip($attach)),
                $attributes
            );
            $changes['attached'] = array_values($attach);
        }

        return $changes;
    }
}