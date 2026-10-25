<?php

namespace Spark\Paginator\Contracts;

/** The existing numbered paginator API, including totals and page counts. */
interface PaginatorContract
{
    /**
     * Retrieves the data collection.
     *
     * @param bool $lazy Whether to use lazy loading or not.
     * @return array The data collection.
     */
    public function data(bool $lazy = false): array;

    /**
     * Checks if the data collection is not empty.
     *
     * @return bool True if the data collection is not empty, false otherwise.
     */
    public function hasData(): bool;

    /**
     * Checks if the pagination links are available.
     *
     * @return bool True if the pagination links are available, false otherwise.
     */
    public function hasLinks(): bool;

    /**
     * Retrieves the pagination links as an HTML string.
     *
     * @param int $links The number of links to show before and after the current page.
     * @param array $classes The CSS classes for the pagination elements.
     * @param array $entity The text entities for the 'previous' and 'next' links.
     * @return \Spark\Support\HtmlString The pagination links as an HTML string.
     */
    public function links(int $links = 2, array $classes = [], array $entity = []): \Spark\Support\HtmlString;

    /**
     * Get the total number of items across all pages.
     *
     * @return int
     */
    public function total(): int;

    /**
     * Get the current page number.
     *
     * @return int
     */
    public function page(): int;

    /**
     * Get the total number of pages.
     *
     * @return int
     */
    public function pages(): int;

    /**
     * Get the number of items per page.
     *
     * @return int
     */
    public function limit(): int;

    /**
     * Get the record offset for the current page.
     *
     * @return int
     */
    public function offset(): int;

    /**
     * Get the items on the current page.
     *
     * @return array
     */
    public function items(): array;

    /**
     * Determine if the paginator has no items.
     *
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * Determine if the paginator has items.
     *
     * @return bool
     */
    public function isNotEmpty(): bool;
}
