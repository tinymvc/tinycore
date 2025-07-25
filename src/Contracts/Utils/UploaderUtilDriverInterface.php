<?php

namespace Spark\Contracts\Utils;

/**
 * UploaderUtilDriverInterface defines the contract for file upload and deletion operations.
 * Implementations of this interface should handle the specifics of file storage, such as local or cloud storage.
 *
 * @package Spark\Contracts\Utils
 */
interface UploaderUtilDriverInterface
{
    /**
     * Upload a file to the specified destination.
     *
     * @param string $filepath The local path of the file to upload.
     * @param string $destination The destination path in the storage.
     * @return bool True on success, false on failure.
     */
    public function upload(string $filepath, string $destination): bool;

    /**
     * Delete a file from the specified destination.
     *
     * @param string $destination The path of the file to delete in the storage.
     * @return bool True on success, false on failure.
     */
    public function delete(string $destination): bool;
}