<?php

namespace Spark\Paginator;

use Spark\Paginator\Contracts\PaginatorContract;
use function array_slice;
use function is_string;

/** Numbered pagination with totals; shares page navigation with SimplePaginator. */
class Paginator extends SimplePaginator implements PaginatorContract
{
    private int $pages = 0;

    public function __construct(public int $total = 0, public int $limit = 10, public string $keyword = 'page')
    {
        $this->total = max(0, $total);
        $this->limit = max(1, $limit);

        parent::__construct([], $this->limit, options: ['pageName' => $keyword]);

        $this->reset();
    }

    public static function make(int $total = 0, int $limit = 10, string $keyword = 'page'): self
    {
        return new self($total, $limit, $keyword);
    }

    public function reset(): self
    {
        $this->pages = (int) ceil($this->total() / $this->perPage());
        $this->page = max(1, min($this->pages(), $this->keywordValue()));
        return $this;
    }

    public function total(): int
    {
        return max(0, $this->total);
    }

    public function pages(): int
    {
        return $this->pages;
    }

    public function lastPage(): int
    {
        return max(1, $this->pages());
    }

    public function perPage(): int
    {
        return max(1, $this->limit);
    }

    public function getPageName(): string
    {
        return $this->keyword;
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage() < $this->pages();
    }

    public function hasPages(): bool
    {
        return $this->pages() > 1;
    }

    public function data(bool $slice = false): array
    {
        return $slice ? array_slice($this->items(), $this->offset(), $this->perPage()) : $this->items();
    }

    /** Preserve the numbered paginator's non-mutating transformation API. */
    public function through(callable $callback): array
    {
        return array_map($callback, $this->items());
    }

    public function paginationLinks(): array
    {
        return ['last' => $this->url($this->lastPage())] + parent::paginationLinks();
    }

    public function paginationMeta(): array
    {
        return parent::paginationMeta() + ['last_page' => $this->lastPage(), 'total' => $this->total()];
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'last_page_url' => $this->url($this->lastPage()),
            'links' => $this->generateLinks(),
        ];
    }

    public function generateLinks(int $links = 2): array
    {
        if ($this->pages() <= 0) {
            return [];
        }

        // Calculate start, end page number.
        $start = max(1, $this->page() - $links);
        $end = min($this->pages(), $this->page() + $links);

        $links = [];

        if ($this->page() > 1) {
            $links[] = [
                'type' => 'previous',
                'url' => $this->url($this->page() - 1),
                'label' => __('Previous'),
            ];
        }

        if ($start > 1) {
            $links = [
                ...$links,
                [
                    'type' => 'page',
                    'url' => $this->url(1),
                    'label' => 1,
                ],
                [
                    'type' => 'ellipsis',
                    'label' => '...',
                ]
            ];
        }

        for ($i = $start; $i <= $end; $i++) {
            $links[] = [
                'type' => 'page',
                'url' => $this->url($i),
                'label' => $i,
                'active' => $this->page() === $i,
            ];
        }

        if ($end < $this->pages()) {
            $links = [
                ...$links,
                [
                    'type' => 'ellipsis',
                    'label' => '...',
                ],
                [
                    'type' => 'page',
                    'url' => $this->url($this->pages()),
                    'label' => $this->pages(),
                ],
            ];
        }

        if ($this->page() < $this->pages()) {
            $links[] = [
                'type' => 'next',
                'url' => $this->url($this->page() + 1),
                'label' => __('Next'),
            ];
        }

        return $links;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && property_exists($this, $offset) || parent::offsetExists($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (is_string($offset) && property_exists($this, $offset)) {
            return $this->{$offset};
        }

        return parent::offsetGet($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_string($offset) && property_exists($this, $offset)) {
            $this->{$offset} = $value;
        } else {
            parent::offsetSet($offset, $value);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset) && property_exists($this, $offset)) {
            unset($this->{$offset});
        } else {
            parent::offsetUnset($offset);
        }
    }
}
