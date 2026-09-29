<?php

namespace Spark\Http\Resources;

use Spark\Http\Request;
use Spark\Utils\Paginator;
use function is_array;

/** A collection of resources, optionally retaining Spark pagination metadata. */
class ResourceCollection extends JsonResource
{
    protected bool $preserveKeys = false;

    /** @param class-string<JsonResource> $collects */
    public function __construct(iterable|Paginator $resource, protected string $collects = JsonResource::class)
    {
        if (!is_a($collects, JsonResource::class, true)) {
            throw new \InvalidArgumentException('Collection items must extend JsonResource.');
        }

        // Materialize generators once so repeated serialization is predictable.
        parent::__construct($resource instanceof Paginator || is_array($resource) ? $resource : iterator_to_array($resource));
    }

    public function preserveKeys(bool $preserve = true): static
    {
        $this->preserveKeys = $preserve;
        return $this;
    }

    public function toArray(?Request $request = null): array
    {
        $items = $this->resource instanceof Paginator ? $this->resource->items() : $this->resource;
        $result = [];

        foreach ($items as $key => $item) {
            $result[$key] = ($item instanceof $this->collects ? $item : new $this->collects($item))->resolve($request);
        }

        return $this->preserveKeys ? $result : array_values($result);
    }

    public function responseData(?Request $request = null): array
    {
        if (!$this->resource instanceof Paginator) {
            return parent::responseData($request);
        }

        $request = $this->resolveRequest($request);
        $page = $this->resource;
        $extra = $this->metadata($request);

        $extra['links'] = array_replace((array) ($extra['links'] ?? []), [
            'first' => $page->url(1),
            'last' => $page->url(max(1, $page->lastPage())),
            'prev' => $page->previousPageUrl(),
            'next' => $page->nextPageUrl(),
        ]);

        $extra['meta'] = array_replace((array) ($extra['meta'] ?? []), [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'from' => $page->firstItem() ?: null,
            'to' => $page->lastItem() ?: null,
        ]);

        return ['data' => $this->resolve($request)] + $extra;
    }
}
