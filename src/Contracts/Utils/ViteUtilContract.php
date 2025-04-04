<?php

namespace Spark\Contracts\Utils;

/**
 * Interface ViteUtilContract
 *
 * Defines the contract for the ViteUtil class, which provides methods to
 * interact with Vite development server.
 */
interface ViteUtilContract
{
    /**
     * Checks if the Vite development server is running for the given entry.
     *
     * @param string $entry The entry file name.
     * @return bool True if the server is running, false otherwise.
     */
    public function isRunning(string $entry): bool;

    /**
     * Gets the asset URL for the given entry file based on the Vite manifest.
     *
     * @param string $entry The entry file name.
     * @return string The URL for the asset.
     */
    public function asset(string $entry): string;

    /**
     * Checks if the Vite manifest exists for the given entry.
     *
     * @return bool True if the manifest exists, false otherwise.
     */
    public function hasManifest(): bool;

    /**
     * Gets the Vite manifest for the given entry.
     *
     * @return array The manifest containing the assets.
     */
    public function getManifest(): array;

    /**
     * Generates the full HTML output including JavaScript and CSS tags.
     *
     * @return string The combined HTML string of JavaScript and CSS tags.
     */
    public function __toString(): string;
}