<?php

namespace Spark\Utils;

use ArrayAccess;
use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Jsonable;
use Spark\Contracts\Utils\PaginatorUtilContract;
use Spark\Support\Traits\Macroable;

/**
 * Class Paginator
 * 
 * This class handles pagination of data, generating paginated results and rendering pagination links.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Paginator implements PaginatorUtilContract, Arrayable, Jsonable, ArrayAccess, \IteratorAggregate
{
    use Macroable;

    /** @var int $pages Total number of pages. */
    private int $pages = 0;

    /** @var int $page Current page number. */
    private int $page = 0;

    /** @var int $offset Offset for paginated data. */
    private int $offset = 0;

    /** @var array $data The data to be paginated. */
    private array $data = [];

    /**
     * Paginator constructor.
     * 
     * Initializes the paginator with total items, limit per page, and 
     * keyword for page number in the URL.
     * 
     * @param int $total Total number of items.
     * @param int $limit Number of items per page.
     * @param string $keyword The URL parameter keyword for the page.
     */
    public function __construct(public int $total = 0, public int $limit = 10, public string $keyword = 'page')
    {
        $this->reset();
    }

    /**
     * Resets the paginator and recalculates pagination values.
     * 
     * @return self
     */
    public function reset(): self
    {
        $this->pages = (int) ceil($this->total() / $this->limit());
        $this->page = min($this->pages(), $this->keywordValue());
        $this->offset = (int) ceil($this->limit() * ($this->page() - 1));

        return $this;
    }

    /**
     * Retrieves the total number of pages.
     * 
     * @return int
     */
    public function pages(): int
    {
        return max($this->pages, 0);
    }

    /**
     * Retrieves the current page number.
     * 
     * @return int
     */
    public function page(): int
    {
        return max($this->page, 0);
    }

    /**
     * Retrieves the offset for the current page.
     * 
     * @return int
     */
    public function offset(): int
    {
        return max($this->offset, 0);
    }

    /**
     * Retrieves the total number of items.
     * 
     * @return int
     */
    public function total(): int
    {
        return max($this->total, 0);
    }

    /**
     * Retrieves the limit of items per page.
     * 
     * @return int
     */
    public function limit(): int
    {
        return max($this->limit, 0);
    }

    /**
     * Retrieves the keyword used for page number in the URL.
     * 
     * @return string
     */
    public function keyword(): string
    {
        return $this->keyword;
    }

    /**
     * Retrieves the current page value from the URL.
     * 
     * @return int
     */
    public function keywordValue(): int
    {
        return filter_input(
            INPUT_GET,
            $this->keyword(),
            FILTER_VALIDATE_INT,
            ['options' => ['default' => 1, 'min_range' => 1]]
        ) ?: 1;
    }

    /**
     * Sets the data array for pagination.
     * 
     * @param array $data The data array.
     * 
     * @return self
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Retrieves the data array or a subset of it, depending on lazy mode.
     * 
     * If lazy mode is enabled, the method returns a subset of the original data
     * array, sliced according to the current offset and limit values.
     * 
     * If lazy mode is disabled (default), the method returns the original data
     * array.
     * 
     * @param bool $lazy Enables or disables lazy mode.
     * 
     * @return array The data array or a subset of it.
     */
    public function data(bool $lazy = false): array
    {
        // Returns sliced items, if lazy mode is enabled. 
        if ($lazy) {
            return array_slice($this->data, $this->offset, $this->limit);
        }

        // Returns actual array items.
        return $this->data;
    }

    /**
     * Retrieves the current items for the current page.
     * 
     * @return array
     */
    public function items(): array
    {
        return $this->data(true);
    }

    /**
     * Checks if there is data available.
     * 
     * @return bool
     */
    public function hasData(): bool
    {
        return !empty($this->data());
    }

    /**
     * Checks if the data array is empty.
     * 
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->data());
    }

    /**
     * Checks if the data array is not empty.
     * 
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Checks if pagination links are needed.
     * 
     * @return bool
     */
    public function hasLinks(): bool
    {
        return $this->pages() > 1;
    }

    /**
     * Maps the data array using a callback function.
     * 
     * @param callable $callback The callback function.
     * 
     * @return self
     */
    public function map(callable $callback): self
    {
        $this->data = array_map($callback, $this->data);
        return $this;
    }

    /**
     * Filters the data array using a callback function.
     * 
     * @param callable $callback The callback function.
     * 
     * @return self
     */
    public function filter(callable $callback): self
    {
        $this->data = array_filter($this->data, $callback);
        return $this;
    }

    /**
     * Reduces the data array to a single value using a callback function.
     * 
     * @param callable $callback The callback function.
     * @param mixed $initial The initial value.
     * 
     * @return mixed
     */
    public function reduce(callable $callback, $initial = null): mixed
    {
        return array_reduce($this->data, $callback, $initial);
    }

    /**
     * Iterates over each item in the data array using a callback function.
     * 
     * @param callable $callback The callback function.
     * 
     * @return self
     */
    public function each(callable $callback): self
    {
        array_walk($this->data, $callback);
        return $this;
    }

    /**
     * Sorts the data array using a callback function.
     * 
     * @param callable $callback The callback function.
     * 
     * @return self
     */
    public function sort(callable $callback): self
    {
        usort($this->data, $callback);
        return $this;
    }

    /**
     * Excludes items from the data array using a callback function.
     * 
     * @param callable $callback The callback function.
     * 
     * @return self
     */
    public function except(callable $callback): self
    {
        $this->data = array_filter($this->data, fn($item) => !$callback($item));
        return $this;
    }

    /**
     * Filters the data array using a callback function.
     * 
     * @param callable $callback The callback function.
     * 
     * @return self
     */
    public function only(callable $callback): self
    {
        $this->data = array_filter($this->data, $callback);
        return $this;
    }

    /**
     * Generates HTML links for pagination.
     * 
     * @param int $links Number of links to show before and after the current page.
     * @param array $classes CSS classes for pagination elements.
     * @param array $entity Text entities for 'previous' and 'next' links.
     * 
     * @return string HTML string with pagination links.
     */
    public function links(int $links = 1, array $classes = [], array $entity = []): string
    {
        // Holds html anchors for pagination.
        $output = [];

        // Calculate start, end page number.
        $start = max(1, $this->page() - $links);
        $end = min($this->pages(), $this->page() + $links);

        //Add dynamic pagination buttons in unordered list...
        $output[] = sprintf('<ul class="%s">', $classes['ul'] ?? 'pagination');

        if ($this->page() > 1) {
            $output[] = sprintf(
                '<li class="%s"><a class="%s" href="%s">%s</a></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link',
                $this->getAnchor($this->page() - 1),
                $entity['prev'] ?? __('Previous')
            );
        }

        if ($start > 1) {
            $output[] = sprintf(
                '<li class="%s"><a class="%s" href="%s">%s</a></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link',
                $this->getAnchor(1),
                1
            );
            $output[] = sprintf(
                '<li class="%s disabled"><span class="%s">...</span></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link'
            );
        }

        for ($i = $start; $i <= $end; $i++) {
            $output[] = sprintf(
                '<li class="%s %s"><a class="%s %s" href="%s">%s</a></li>',
                $classes['li'] ?? 'page-item',
                $this->page() === $i ? ($classes['li.current'] ?? 'active') : '',
                $classes['a'] ?? 'page-link',
                $this->page() === $i ? ($classes['a.current'] ?? '') : '',
                $this->getAnchor($i),
                $i
            );
        }

        if ($end < $this->pages()) {
            $output[] = sprintf(
                '<li class="%s disabled"><span class="%s">...</span></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link'
            );

            $output[] = sprintf(
                '<li class="%s"><a class="%s" href="%s">%s</a></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link',
                $this->getAnchor($this->pages()),
                $this->pages()
            );
        }

        if ($this->page() < $this->pages()) {
            $output[] = sprintf(
                '<li class="%s"><a class="%s" href="%s">%s</a></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link',
                $this->getAnchor($this->page() + 1),
                $entity['next'] ?? __('Next')
            );
        }

        $output[] = '</ul>';

        // Returns html output of pagination links.
        return implode('', $output);
    }

    /**
     * Converts the paginator instance to an array.
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'pages' => $this->pages(),
            'page' => $this->page(),
            'offset' => $this->offset(),
            'limit' => $this->limit(),
            'first_item' => $this->firstItem(),
            'last_item' => $this->lastItem(),
            'total' => $this->total(),
            'keyword' => $this->keyword(),
            'data' => $this->data(),
        ];
    }

    /**
     * Converts the paginator instance to a JSON string.
     * 
     * @param int $options JSON encoding options.
     * 
     * @return string
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Get an iterator for the items.
     * 
     * @template TKey of array-key
     *
     * @template-covariant TValue
     *
     * @implements \ArrayAccess<TKey, TValue>
     *
     * @return \ArrayIterator<TKey, TValue>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->data());
    }

    /**
     * Get the first item number in the current page.
     * 
     * @return int The first item number.
     */
    public function firstItem(): int
    {
        return max($this->offset(), 1);
    }

    /**
     * Get the last item number in the current page.
     * 
     * @return int The last item number.
     */
    public function lastItem(): int
    {
        return min($this->offset() + $this->limit(), $this->total());
    }

    /**
     * Check if the response was a success (2xx status code).
     *
     * @param mixed $offset The offset to check.
     * @return bool True if success, false otherwise.
     */
    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset) || isset($this->data[$offset]);
    }

    /**
     * Get the value at the specified offset.
     *
     * @param mixed $offset The offset to retrieve.
     * @return mixed The value at the specified offset or null if not set.
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (property_exists($this, $offset)) {
            return $this->{$offset};
        }

        return $this->data[$offset] ?? null;
    }

    /**
     * Set the value at the specified offset.
     *
     * @param mixed $offset The offset to set.
     * @param mixed $value The value to set at the specified offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (property_exists($this, $offset)) {
            $this->{$offset} = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    /**
     * Unset the value at the specified offset.
     *
     * @param mixed $offset The offset to unset.
     */
    public function offsetUnset(mixed $offset): void
    {
        if (property_exists($this, $offset)) {
            unset($this->{$offset});
        } else {
            unset($this->data[$offset]);
        }
    }

    /**
     * Create a copy of the paginator instance.
     *
     * @return self A new instance that is a copy of the current instance.
     */
    public function copy(): self
    {
        return clone $this;
    }

    /**
     * Generates the URL for a specific page.
     * 
     * @param int $page The page number.
     * 
     * @return string URL with the page query parameter.
     */
    private function getAnchor(int $page): string
    {
        return home_url(
            request()->getPath() . '?' . http_build_query(
                array_merge(request()->getQueryParams(), [$this->keyword() => $page])
            )
        );
    }
}
