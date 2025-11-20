<?php

namespace Spark\Contracts\Utils;

use Spark\Contracts\Support\Arrayable;

/**
 * Interface UploaderUtilContract
 *
 * This interface defines the contract for the Uploader util class.
 * The Uploader class provides methods for handling file uploads.
 */
interface UploaderUtilContract
{
    /**
     * Uploads a file or multiple files.
     * 
     * @param string|array|Arrayable $files Array containing file details (e.g., $_FILES['file']).
     * @return string|array Returns the file path(s) of the uploaded file(s).
     */
    public function upload(string|array|Arrayable $files): string|array;

    /**
     * Deletes a file or multiple files.
     *
     * @param string|array $file The file path(s) to delete.
     * @return bool Returns true if the file(s) were successfully deleted, false otherwise.
     */
    public function delete(string|array $file): bool;
}