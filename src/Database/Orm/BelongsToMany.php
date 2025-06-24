<?php

namespace Spark\Database\Orm;

use Closure;
use Spark\Database\Model;

class BelongsToMany extends Relation
{
    public function __construct(
        private string $related,
        private ?string $table = null,
        private ?string $foreignPivotKey = null,
        private ?string $relatedPivotKey = null,
        private ?string $parentKey = null,
        private ?string $relatedKey = null,
        private bool $lazy = true,
        private ?Closure $callback = null,
        ?Model $model = null
    ) {
        parent::__construct($model);
    }

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
            'callback' => $this->callback,
        ];
    }
}