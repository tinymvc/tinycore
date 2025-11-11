<?php

namespace Spark\Database\Relation;

use Closure;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;

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
     * @param array $append The additional fields to append to the relationship.
     * @param Closure|null $callback An optional callback to modify the query for the relationship.
     * @param Model|null $model The model instance that this relationship belongs to.
     */
    public function __construct(
        private string $related,
        private ?string $table = null,
        private ?string $foreignPivotKey = null,
        private ?string $relatedPivotKey = null,
        private ?string $parentKey = null,
        private ?string $relatedKey = null,
        private bool $lazy = true,
        private array $append = [],
        private ?Closure $callback = null,
        ?Model $model = null,
    ) {
        parent::__construct($model);
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
            'lazy' => $this->lazy,
            'append' => $this->append,
            'callback' => $this->callback,
        ];
    }

    /**
     * Attach a model to the parent via the pivot table.
     * 
     * @param mixed $id The ID(s) of the model(s) to attach.
     * @param array $attributes Additional pivot table attributes.
     * @return void
     */
    public function attach($id, array $attributes = []): void
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot attach without a parent model instance.');
        }

        $parentKeyValue = $parent->{$this->parentKey};

        if (empty($parentKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->parentKey} must be set before attaching related models.");
        }

        $ids = is_array($id) ? $id : [$id];
        $records = [];

        foreach ($ids as $relatedId) {
            $pivotData = array_merge($attributes, [
                $this->foreignPivotKey => $parentKeyValue,
                $this->relatedPivotKey => $relatedId,
            ]);

            $records[] = $pivotData;
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
     * @param mixed $ids The ID(s) of the model(s) to detach. If null, detach all.
     * @return int The number of rows affected.
     */
    public function detach($ids = null): int
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
            $ids = is_array($ids) ? $ids : [$ids];
            $query->whereIn($this->relatedPivotKey, $ids);
        }

        return $query->delete();
    }

    /**
     * Sync the intermediate table with a list of IDs.
     * 
     * @param array|int $ids The IDs to sync.
     * @param bool $detaching Whether to detach missing models.
     * @return array Array with 'attached', 'detached', and 'updated' keys.
     */
    public function sync($ids, bool $detaching = true): array
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot sync without a parent model instance.');
        }

        $parentKeyValue = $parent->{$this->parentKey};

        if (empty($parentKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->parentKey} must be set before syncing related models.");
        }

        $changes = [
            'attached' => [],
            'detached' => [],
            'updated' => [],
        ];

        // Normalize the input
        if (!is_array($ids)) {
            $ids = [$ids => []];
        } elseif (array_keys($ids) === range(0, count($ids) - 1)) {
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
            ->all();

        $syncIds = array_keys($ids);

        // Detach models that are no longer in the list
        if ($detaching) {
            $detach = array_diff($currentIds, $syncIds);
            if (!empty($detach)) {
                $this->detach($detach);
                $changes['detached'] = $detach;
            }
        }

        // Attach new models
        $attach = array_diff($syncIds, $currentIds);
        foreach ($attach as $id) {
            $this->attach($id, $ids[$id]);
            $changes['attached'][] = $id;
        }

        return $changes;
    }

    /**
     * Toggle the attachment of models to the parent.
     * 
     * @param mixed $ids The ID(s) to toggle.
     * @param array $attributes Additional pivot table attributes.
     * @return array Array with 'attached' and 'detached' keys.
     */
    public function toggle($ids, array $attributes = []): array
    {
        $parent = $this->getParentModel();

        if (!$parent) {
            throw new \RuntimeException('Cannot toggle without a parent model instance.');
        }

        $parentKeyValue = $parent->{$this->parentKey};

        if (empty($parentKeyValue)) {
            throw new \RuntimeException("Parent model's {$this->parentKey} must be set before toggling related models.");
        }

        $changes = [
            'attached' => [],
            'detached' => [],
        ];

        $ids = is_array($ids) ? $ids : [$ids];

        /** @var \Spark\Database\QueryBuilder to insert into pivot table */
        $query = app(QueryBuilder::class);

        // Get currently attached IDs
        $currentIds = $query->table($this->table)
            ->where($this->foreignPivotKey, $parentKeyValue)
            ->whereIn($this->relatedPivotKey, $ids)
            ->select($this->relatedPivotKey)
            ->fetchColumn()
            ->all();

        // Detach currently attached
        $detach = array_intersect($ids, $currentIds);
        if (!empty($detach)) {
            $this->detach($detach);
            $changes['detached'] = $detach;
        }

        // Attach currently detached
        $attach = array_diff($ids, $currentIds);
        if (!empty($attach)) {
            foreach ($attach as $id) {
                $this->attach($id, $attributes);
                $changes['attached'][] = $id;
            }
        }

        return $changes;
    }
}