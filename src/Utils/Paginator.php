<?php

namespace Spark\Utils;

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
class Paginator implements PaginatorUtilContract
{
    use Macroable;

    /**
     * @var int $pages Total number of pages.
     */
    private int $pages = 0;

    /**
     * @var int $page Current page number.
     */
    private int $page = 0;

    /**
     * @var int $offset Offset for paginated data.
     */
    private int $offset = 0;

    /**
     * @var array $data The data to be paginated.
     */
    private array $data = [];

    /**
     * Constructor
     * 
     * @param int $total Total number of items.
     * @param int $limit Number of items per page.
     * @param string $keyword The URL parameter keyword for the page.
     */
    public function __construct(
        public int $total = 0,
        public int $limit = 10,
        public string $keyword = 'page'
    ) {
        $this->resetPaginator();
    }

    /**
     * Resets the paginator and recalculates pagination values.
     * 
     * @return self
     */
    public function resetPaginator(): self
    {
        $this->pages = (int) ceil($this->getTotal() / $this->getLimit());
        $this->page = min($this->getPages(), $this->getKeywordValue());
        $this->offset = (int) ceil($this->getLimit() * ($this->getPage() - 1));

        return $this;
    }

    /**
     * Retrieves the total number of pages.
     * 
     * @return int
     */
    public function getPages(): int
    {
        return max($this->pages, 0);
    }

    /**
     * Retrieves the current page number.
     * 
     * @return int
     */
    public function getPage(): int
    {
        return max($this->page, 0);
    }

    /**
     * Retrieves the offset for the current page.
     * 
     * @return int
     */
    public function getOffset(): int
    {
        return max($this->offset, 0);
    }

    /**
     * Retrieves the total number of items.
     * 
     * @return int
     */
    public function getTotal(): int
    {
        return max($this->total, 0);
    }

    /**
     * Retrieves the limit of items per page.
     * 
     * @return int
     */
    public function getLimit(): int
    {
        return max($this->limit, 0);
    }

    /**
     * Retrieves the keyword used for page number in the URL.
     * 
     * @return string
     */
    public function getKeyword(): string
    {
        return $this->keyword;
    }

    /**
     * Retrieves the current page value from the URL.
     * 
     * @return int
     */
    public function getKeywordValue(): int
    {
        return filter_input(
            INPUT_GET,
            $this->getKeyword(),
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
    public function getData(bool $lazy = false): array
    {
        // Returns sliced items, if lazy mode is enabled. 
        if ($lazy) {
            return array_slice($this->data, $this->offset, $this->limit);
        }

        // Returns actual array items.
        return $this->data;
    }

    /**
     * Checks if there is data available.
     * 
     * @return bool
     */
    public function hasData(): bool
    {
        return !empty($this->getData());
    }

    /**
     * Checks if pagination links are needed.
     * 
     * @return bool
     */
    public function hasLinks(): bool
    {
        return $this->getPages() > 1;
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
    public function getLinks(int $links = 2, array $classes = [], array $entity = []): string
    {
        // Holds html anchors for pagination.
        $output = [];

        // Calculate start, end page number.
        $start = max(1, $this->getPage() - $links);
        $end = min($this->getPages(), $this->getPage() + $links);

        //Add dynamic pagination buttons in unordered list...
        $output[] = sprintf('<ul class="%s">', $classes['ul'] ?? 'pagination');

        if ($this->getPage() > 1) {
            $output[] = sprintf(
                '<li class="%s"><a class="%s" href="%s">%s</a></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link',
                $this->getAnchor($this->getPage() - 1),
                $entity['prev'] ?? __('previous')
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
                $this->getPage() === $i ? ($classes['li.current'] ?? 'active') : '',
                $classes['a'] ?? 'page-link',
                $this->getPage() === $i ? ($classes['a.current'] ?? '') : '',
                $this->getAnchor($i),
                $i
            );
        }

        if ($end < $this->getPages()) {
            $output[] = sprintf(
                '<li class="%s disabled"><span class="%s">...</span></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link'
            );

            $output[] = sprintf(
                '<li class="%s"><a class="%s" href="%s">%s</a></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link',
                $this->getAnchor($this->getPages()),
                $this->getPages()
            );
        }

        if ($this->getPage() < $this->getPages()) {
            $output[] = sprintf(
                '<li class="%s"><a class="%s" href="%s">%s</a></li>',
                $classes['li'] ?? 'page-item',
                $classes['a'] ?? 'page-link',
                $this->getAnchor($this->getPage() + 1),
                $entity['next'] ?? __('next')
            );
        }

        $output[] = '</ul>';

        // Returns html output of pagination links.
        return implode('', $output);
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
        return url(
            request()->getPath() . '?' . http_build_query(
                array_merge(request()->getQueryParams(), [$this->getKeyword() => $page])
            )
        );
    }
}
