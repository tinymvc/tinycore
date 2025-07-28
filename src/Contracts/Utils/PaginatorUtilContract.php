<?php

namespace Spark\Contracts\Utils;

/**
 * Interface PaginatorUtilContract
 *
 * This interface defines the contract for the PaginatorUtil class.
 * It outlines the methods that must be implemented by the class.
 */
interface PaginatorUtilContract
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
     * @return string The pagination links as an HTML string.
     */
    public function links(int $links = 2, array $classes = [], array $entity = []): string;
}