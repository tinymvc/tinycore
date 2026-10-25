<?php

namespace Spark\Paginator;

use function array_slice;
use function count;

/** Offset pagination with one lookahead row instead of a total-count query. */
class SimplePaginator extends AbstractPaginator
{
    protected int $page;
    protected bool $hasMore;
    protected string $pageName;

    public function __construct(array $items, protected int $perPage = 15, ?int $page = null, array $options = [])
    {
        $this->perPage = max(1, $perPage);
        $this->pageName = $options['pageName'] ?? 'page';
        $this->page = max(1, $page ?? static::resolveCurrentPage($this->pageName));
        $this->hasMore = count($items) > $this->perPage();

        $this->initialize(array_slice($items, 0, $this->perPage()), $options);
    }

    public function currentPage(): int
    {
        return $this->page;
    }

    public function page(): int
    {
        return $this->currentPage();
    }

    public function firstPage(): int
    {
        return 1;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function limit(): int
    {
        return $this->perPage();
    }

    public function getPageName(): string
    {
        return $this->pageName;
    }

    public function keyword(): string
    {
        return $this->getPageName();
    }

    public function keywordValue(): int
    {
        return static::resolveCurrentPage($this->getPageName());
    }

    public function offset(): int
    {
        return ($this->currentPage() - 1) * $this->perPage();
    }

    public function firstItem(): int
    {
        return $this->isEmpty() ? 0 : $this->offset() + 1;
    }

    public function lastItem(): int
    {
        return $this->isEmpty() ? 0 : $this->offset() + $this->count();
    }

    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage() === 1;
    }

    public function url(int $page): string
    {
        return $this->urlWithParameter($this->getPageName(), max(1, $page));
    }

    public function previousPageUrl(): ?string
    {
        return $this->onFirstPage() ? null : $this->url($this->currentPage() - 1);
    }

    public function nextPageUrl(): ?string
    {
        return $this->hasMorePages() ? $this->url($this->currentPage() + 1) : null;
    }

    public function paginationLinks(): array
    {
        return ['first' => $this->url(1)] + parent::paginationLinks();
    }

    public function paginationMeta(): array
    {
        return [
            'current_page' => $this->currentPage(),
            'per_page' => $this->perPage(),
            'from' => $this->firstItem() ?: null,
            'to' => $this->lastItem() ?: null,
        ];
    }

    public function toArray(): array
    {
        return $this->paginationMeta() + [
            'data' => $this->collection->toArray(),
            'first_page_url' => $this->url(1),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->previousPageUrl(),
            'path' => $this->path(),
        ];
    }
}
