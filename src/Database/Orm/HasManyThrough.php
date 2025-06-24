<?php

namespace Spark\Database\Orm;

use Closure;
use Spark\Contracts\Support\Arrayable;

class HasManyThrough implements Arrayable
{
    public function __construct(
        private string $related,
        private string $through,
        private ?string $firstKey = null,
        private ?string $secondKey = null,
        private ?string $localKey = null,
        private ?string $secondLocalKey = null,
        private bool $lazy = true,
        private ?Closure $callback = null
    ) {
    }

    public function getConfig(): array
    {
        return [
            'related' => $this->related,
            'through' => $this->through,
            'firstKey' => $this->firstKey,
            'secondKey' => $this->secondKey,
            'localKey' => $this->localKey,
            'secondLocalKey' => $this->secondLocalKey,
            'lazy' => $this->lazy,
            'callback' => $this->callback,
        ];
    }
}