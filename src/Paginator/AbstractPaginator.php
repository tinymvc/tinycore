<?php

namespace Spark\Paginator;

use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Htmlable;
use Spark\Contracts\Support\Jsonable;
use Spark\Support\Collection;
use Spark\Support\HtmlString;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function in_array;
use function is_array;

/** Shared item handling, URL generation, rendering, and serialization. */
abstract class AbstractPaginator implements Arrayable, Htmlable, Jsonable, \JsonSerializable, \IteratorAggregate, \Countable, \ArrayAccess, \Stringable
{
    use Conditionable, Macroable;

    protected Collection $collection;
    protected string $path;
    protected array $query;

    protected function initialize(array $items, array $options = []): void
    {
        $this->collection = new Collection($items);
        $this->path = $options['path'] ?? home_url(request()->getPath());
        $this->query = $options['query'] ?? request()->query->all();
    }

    abstract public function perPage(): int;
    abstract public function hasMorePages(): bool;
    abstract public function previousPageUrl(): ?string;
    abstract public function nextPageUrl(): ?string;
    abstract public function paginationMeta(): array;

    public static function resolveCurrentPage(string $pageName = 'page'): int
    {
        return filter_var(request()->query($pageName, 1), FILTER_VALIDATE_INT, [
            'options' => ['default' => 1, 'min_range' => 1],
        ]) ?: 1;
    }

    public function items(): array
    {
        return $this->collection->all();
    }

    public function data(): array
    {
        return $this->items();
    }

    public function setData(array $data): static
    {
        return $this->setCollection(new Collection($data));
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function setCollection(Collection $collection): static
    {
        $this->collection = $collection;
        return $this;
    }

    public function map(callable $callback): static
    {
        return $this->setCollection($this->collection->map($callback));
    }

    /** Numbered Paginator retains its legacy non-mutating array return. */
    public function through(callable $callback): static|array
    {
        return $this->map($callback);
    }

    public function filter(callable $callback): static
    {
        return $this->setData(array_filter($this->items(), $callback));
    }

    public function only(callable $callback): static
    {
        return $this->filter($callback);
    }

    public function except(callable $callback): static
    {
        return $this->filter(fn($item) => !$callback($item));
    }

    public function each(callable $callback): static
    {
        $items = $this->items();
        array_walk($items, $callback);
        return $this->setData($items);
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items(), $callback, $initial);
    }

    public function pluck(string $key): array
    {
        return array_map(fn($item) => is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null), $this->items());
    }

    public function sort(callable $callback): static
    {
        $items = $this->items();
        usort($items, $callback);
        return $this->setData($items);
    }

    public function isEmpty(): bool
    {
        return $this->collection->isEmpty();
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    public function hasData(): bool
    {
        return $this->isNotEmpty();
    }

    public function count(): int
    {
        return $this->collection->count();
    }

    public function onFirstPage(): bool
    {
        return $this->previousPageUrl() === null;
    }

    public function onLastPage(): bool
    {
        return !$this->hasMorePages();
    }

    public function hasPages(): bool
    {
        return !$this->onFirstPage() || $this->hasMorePages();
    }

    public function hasLinks(): bool
    {
        return $this->hasPages();
    }

    public function path(): string
    {
        return $this->path;
    }

    protected function urlWithParameter(string $name, string|int|null $value): string
    {
        $query = $this->query;
        unset($query[$name]);
        if ($value !== null) {
            $query[$name] = $value;
        }

        return $this->path() . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function paginationLinks(): array
    {
        return ['prev' => $this->previousPageUrl(), 'next' => $this->nextPageUrl()];
    }

    public function generateLinks(int $links = 2): array
    {
        $result = [];
        foreach ($this->paginationLinks() as $type => $url) {
            if ($url !== null && in_array($type, ['prev', 'next'], true)) {
                $result[] = [
                    'type' => $type === 'prev' ? 'previous' : 'next',
                    'url' => $url,
                    'label' => $type === 'prev' ? __('Previous') : __('Next'),
                ];
            }
        }
        return $result;
    }

    public function links(int $links = 2, array $classes = [], array $entity = []): HtmlString
    {
        $output = '<ul class="' . e($classes['ul'] ?? 'pagination') . '">';
        foreach ($this->generateLinks($links) as $link) {
            $type = $link['type'];
            $active = $link['active'] ?? false;
            $itemClass = $classes['li'] ?? 'page-item';
            $linkClass = $classes['a'] ?? 'page-link';
            if ($active) {
                $itemClass .= ' ' . ($classes['li.current'] ?? 'active');
                $linkClass .= ' ' . ($classes['a.current'] ?? '');
            }

            $labelKey = $type === 'previous' ? 'prev' : $type;
            $label = e($entity[$labelKey] ?? $link['label']);
            if ($type === 'ellipsis') {
                $output .= '<li class="' . e("$itemClass disabled") . '"><span class="' . e($linkClass) . '">' . $label . '</span></li>';
                continue;
            }

            $output .= '<li class="' . e($itemClass) . '"><a class="' . e($linkClass) . '" href="'
                . e($link['url']) . '">' . $label . '</a></li>';
        }
        return new HtmlString("$output</ul>");
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toHtml(): HtmlString
    {
        return $this->links();
    }

    public function __toString(): string
    {
        return (string) $this->links();
    }

    public function getIterator(): \Traversable
    {
        return $this->collection->getIterator();
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->collection->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->collection->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->collection->put($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->collection->forget($offset);
    }

    public function copy(): static
    {
        return clone $this;
    }

    public function __clone(): void
    {
        $this->collection = clone $this->collection;
    }
}
