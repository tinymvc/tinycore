<?php

namespace Spark\Contracts\Utils;

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
     * @param array $files Array containing file details (e.g., $_FILES['file']).
     * @return string|array Returns the file path(s) of the uploaded file(s).
     */
    public function upload(array $files): string|array;
}