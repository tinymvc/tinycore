<?php

namespace Spark\Http\Resources;

use Spark\Http\Request;
use Spark\Paginator\AbstractPaginator;
use function is_array;

/** A collection of resources, optionally retaining Spark pagination metadata. */
class ResourceCollection extends JsonResource
{
    protected bool $preserveKeys = false;

    /** @param class-string<JsonResource> $collects */
    public function __construct(iterable|AbstractPaginator $resource, protected string $collects = JsonResource::class)
    {
        if (!is_a($collects, JsonResource::class, true)) {
            throw new \InvalidArgumentException('Collection items must extend JsonResource.');
        }

        // Materialize generators once so repeated serialization is predictable.
        parent::__construct($resource instanceof AbstractPaginator || is_array($resource) ? $resource : iterator_to_array($resource));
    }

    public function preserveKeys(bool $preserve = true): static
    {
        $this->preserveKeys = $preserve;
        return $this;
    }

    public function toArray(?Request $request = null): array
    {
        $items = $this->resource instanceof AbstractPaginator ? $this->resource->items() : $this->resource;
        $result = [];

        foreach ($items as $key => $item) {
            $result[$key] = ($item instanceof $this->collects ? $item : new $this->collects($item))->resolve($request);
        }

        return $this->preserveKeys ? $result : array_values($result);
    }

    public function responseData(?Request $request = null): array
    {
        if (!$this->resource instanceof AbstractPaginator) {
            return parent::responseData($request);
        }

        $request = $this->resolveRequest($request);
        $extra = $this->metadata($request);
        $extra['links'] = array_replace((array) ($extra['links'] ?? []), $this->resource->paginationLinks());
        $extra['meta'] = array_replace((array) ($extra['meta'] ?? []), $this->resource->paginationMeta());

        return ['data' => $this->resolve($request)] + $extra;
    }
}
