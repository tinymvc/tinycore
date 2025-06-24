<?php

namespace Spark\Database\Orm;

use Closure;
use Spark\Contracts\Support\Arrayable;

class HasMany implements Arrayable
{
    public function __construct(
        private string $related,
        private ?string $foreignKey = null,
        private ?string $localKey = null,
        private bool $lazy = true,
        private ?Closure $callback = null
    ) {
    }

    public function getConfig(): array
    {
        return [
            'related' => $this->related,
            'foreignKey' => $this->foreignKey,
            'localKey' => $this->localKey,
            'lazy' => $this->lazy,
            'callback' => $this->callback,
        ];
    }
}