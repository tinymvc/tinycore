<?php

namespace Spark\Database\HasUploads;

use Spark\Utils\Uploader;

/**
 * Trait Uploader
 * 
 * Provides functionality for handling file uploads within a PHP application.
 * Includes methods for uploading, managing, and removing files, as well as handling
 * various upload configurations defined in the implementing class.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
trait HasUploads
{
    /**
     * Abstract method to define upload configurations.
     * 
     * Should return an array of upload configurations. Each configuration should
     * be an associative array with the following key-value pairs:
     * 
     * - `name`: The name of the HTML form field containing the file input.
     * - `uploadDir`: The directory where the uploaded files should be saved.
     * - `uploadTo`: The subdirectory within the `uploadDir` where the uploaded
     *   file should be saved.
     * 
     * Example:
     * [
     *     [
     *         'name' => 'image',
     *         'uploadTo' => 'users/avatars',
     *         'maxSize' => 1048576 // 1MB,
     *         'compress' => 65,
     *         'resizes' => [140 => 140, 60 => 60]
     *     ],
     * ]
     * 
     * @return array The upload configurations.
     */
    abstract protected function uploader(): array;

    /**
     * Processes and handles file uploads, updating the provided data array
     * with the paths of the uploaded files.
     * 
     * @param array $data The data array containing file input fields.
     * @return array The updated data array with the paths of the uploaded files.
     */
    protected function uploadChanges(array $data): array
    {
        foreach ($this->uploads() as $upload) {
            // Get the name of the file input field
            $name = $upload['name'];
            $upload['uploadDir'] = dir_path(config('upload_dir') . '/' . ($upload['uploadTo'] ?? ''));
            unset($upload['name'], $upload['uploadTo']);

            // Perform the file upload
            if (is_array($data[$name] ?? null) && isset($data[$name]['size'])) {
                $data[$name] = $this->doUpload($name, $upload, $data[$name]);
            }
        }

        // Return the updated data
        return $data;
    }

    /**
     * Handles the upload of files, replacing old files with new ones if provided.
     *
     * This method checks for uploaded files, saves them using the configured
     * uploader, and removes any old files from the system. If no new files are
     * uploaded, it returns the existing old files.
     *
     * @param string $name The name of the file input field.
     * @param array $upload Configuration array for the uploader.
     * @param array|null $files The files to be uploaded, or null if no files are provided.
     * @return null|string|array The path(s) of the saved files, or null if no files were saved.
     */
    protected function doUpload(string $name, array $upload, ?array $files): null|string|array
    {
        $saved = null;

        // Get old files from hidden fiels.
        $oldFiles = array_filter(
            array_map(
                'trim',
                explode(',', request()->post("_$name", ''))
            )
        );

        // Check if files are uploaded.
        if ($this->hasUploads($files)) {
            // Get the uploader callback if exixts
            $callback = $upload['callback'] ?? null;

            unset($upload['callback']);

            // Save the newly uploaded files and get their paths.
            $uploader = get(Uploader::class)
                ->setup(...$upload);

            // Clear the base upload directory prefix from the saved paths.
            $saved = $this->clearSavedPath(
                $callback !== null && is_callable($callback) ? call_user_func($callback, $uploader, $files) : $uploader->upload($files),
                config('upload_dir')
            );

            // Remove old files from the file system if they exist.
            $this->removeFiles($oldFiles);
        } else {
            // return old files when nothing to update.
            $saved = count($oldFiles) === 1 ? $oldFiles[0] : $oldFiles;
        }

        return empty($saved) ? null : $saved;
    }

    /**
     * Checks if any files are uploaded in the given array of file information.
     *
     * If no files are provided, this method returns false. If the provided files
     * have a non-zero size, this method returns true. Otherwise, it returns false.
     *
     * @param array|null $files The files to check, or null if no files are provided.
     * @return bool True if any files are uploaded, false otherwise.
     */
    protected function hasUploads(?array $files): bool
    {
        if (!isset($files)) {
            return false;
        }

        $size = $files['size'] ?? 0;

        // Handle multiple files (array of sizes) or a single file (integer size)
        if (is_array($size)) {
            foreach ($size as $fileSize) {
                if ($fileSize > 0) {
                    return true;
                }
            }
            return false;
        }

        return $size > 0;
    }

    /**
     * Removes previously uploaded files specified in the provided data array.
     * 
     * @param array $data The data array containing paths of files to be removed.
     */
    protected function removeUploaded(array $data): void
    {
        foreach ($this->uploads() as $upload) {
            $this->removeFiles($data[$upload['name']] ?? []);
        }
    }

    /**
     * Retrieves and processes the upload configurations defined in the uploader method.
     * Ensures the configurations are properly structured for processing.
     * 
     * @return array The processed upload configurations.
     */
    public function uploads(): array
    {
        $uploads = $this->uploader();
        if (!empty($uploads) && !(isset($uploads[0]) && is_array($uploads[0]))) {
            $uploads = [$uploads];
        }

        return $uploads;
    }

    /**
     * Removes specified files from the file system.
     * 
     * @param string|array $files The file(s) to be removed.
     */
    protected function removeFiles(string|array $files): void
    {
        foreach ((array) $files as $file) {
            $filePath = dir_path(config('upload_dir') . '/' . $file);
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
    }

    /**
     * Cleans and normalizes the saved file path by removing the base upload directory prefix.
     * 
     * @param array|string $path The path(s) to be cleaned.
     * @param string $basePath The base directory path to be removed.
     * @return array|string
     */
    private function clearSavedPath(array|string $path, string $basePath): array|string
    {
        if (is_array($path)) {
            $paths = [];
            foreach ($path as $p) {
                $paths[] = $this->clearSavedPath($p, $basePath);
            }

            return $paths;
        }

        $path = str_replace(dir_path($basePath), '', dir_path($path));
        $path = trim($path, DIRECTORY_SEPARATOR);
        $path = trim($path, '/');

        // The cleaned path(s).
        return $path;
    }
}
