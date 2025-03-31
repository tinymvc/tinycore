<?php

namespace Spark\Utils;

use RuntimeException;

/**
 * Class uploader
 *
 * Handles file uploads with options for validating file extensions, setting a maximum file size, 
 * supporting multiple files, resizing and compressing images, and storing files in a specific directory.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Uploader
{
    /**
     * Upload directory path.
     *
     * @var string
     */
    public string $uploadDir;

    /**
     * Supported file extensions.
     *
     * @var array
     */
    public array $extensions;

    /**
     * Whether to support multiple file uploads.
     *
     * @var bool
     */
    public bool $multiple;

    /**
     * Maximum file size (in bytes).
     *
     * @var int|null
     */
    public ?int $maxSize;

    /**
     * Resize options for images.
     *
     * @var array|null
     */
    public ?array $resize;

    /**
     * Bulk resize options for images.
     *
     * @var array|null
     */
    public ?array $resizes;

    /**
     * Compression level for images.
     *
     * @var int|null
     */
    public ?int $compress;

    /**
     * Sets up the uploader configuration.
     *
     * @param string $uploadDir Upload directory path.
     * @param array $extensions Supported file extensions.
     * @param bool $multiple Whether to support multiple file uploads.
     * @param int|null $maxSize Maximum file size (in bytes).
     * @param array|null $resize Resize options for images.
     * @param array|null $resizes Bulk resize options for images.
     * @param int|null $compress Compression level for images.
     */
    public function setup(
        string $uploadDir = 'uploads',
        array $extensions = [],
        bool $multiple = false,
        ?int $maxSize = 2097152,
        ?array $resize = null,
        ?array $resizes = null,
        ?int $compress = null
    ): self {
        $this->extensions = $extensions;
        $this->multiple = $multiple;
        $this->maxSize = $maxSize;
        $this->resize = $resize;
        $this->resizes = $resizes;
        $this->compress = $compress;

        // Set the upload directory
        return $this->setUploadDir($uploadDir);
    }

    /**
     * Sets the upload directory.
     *
     * @param string $uploadDir Upload directory path.
     * @return $this
     * @throws RuntimeException If the upload directory cannot be created or is not writable.
     */
    public function setUploadDir(string $uploadDir): self
    {
        // Ensure the upload directory exists and is writable
        if (!is_dir($uploadDir)) {
            // Create the upload directory
            if (!mkdir($uploadDir, 0777, true)) {
                throw new RuntimeException(__('Failed to create upload directory.'));
            }
        } elseif (!is_writable($uploadDir)) {
            // Make the upload directory writable
            if (!chmod($uploadDir, 0777)) {
                throw new RuntimeException(__('Upload directory is not writable.'));
            }
        }

        $this->uploadDir = dir_path($uploadDir);
        return $this;
    }

    /**
     * Uploads a file or multiple files.
     *
     * @param array $files Array containing file details (e.g., $_FILES['file']).
     * @return string|array Returns the file path(s) of the uploaded file(s).
     */
    public function upload(array $files): string|array
    {
        if ($this->multiple) {
            $uploadedFiles = [];
            foreach ($files['name'] as $key => $name) {
                $file = [
                    'name' => $files['name'][$key],
                    'type' => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error' => $files['error'][$key],
                    'size' => $files['size'][$key],
                ];
                $uploadedFiles = array_merge($uploadedFiles, (array) $this->processUpload($file));
            }
            return $uploadedFiles;
        } else {
            return $this->processUpload($files);
        }
    }

    /** @Add helpers methods for uploader object */

    /**
     * Processes the upload for a single file, including validation, file renaming, and optional resizing/compression.
     *
     * @param array $file Array containing file details such as name, type, tmp_name, error, and size.
     * @return array|string Returns the file path of the uploaded file or an array of paths if resizing options are applied.
     * @throws RuntimeException If file validation fails or moving the file fails.
     */
    protected function processUpload(array $file): array|string
    {
        // Validate file size
        if (isset($this->maxSize) && $file['size'] > $this->maxSize) {
            throw new RuntimeException(__('File size exceeds the maximum limit.'));
        }

        // Validate file extension
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!empty($this->extensions) && !in_array(strtolower($extension), $this->extensions)) {
            throw new RuntimeException(__('Invalid file extension.'));
        }

        // Create a unique file name
        $filename = $this->generateUniqueFileName($file['name']);
        $destination = dir_path("{$this->uploadDir}/$filename");

        // Move the uploaded file to the destination
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException(__('Failed to move uploaded file.'));
        }

        // Compress, resize, and bulk resize image if options are set and the file is an image
        if ((isset($this->compress) || isset($this->resize) || isset($this->resizes)) && in_array($extension, ['jpg', 'jpeg', 'png'])) {
            $image = new Image($destination);
            if (isset($this->compress)) {
                $image->compress($this->compress);
            }
            if (isset($this->resize)) {
                $image->resize(
                    array_keys($this->resize)[0],
                    array_values($this->resize)[0]
                );
            }
            if (isset($this->resizes)) {
                $destination = array_merge(
                    [$destination],
                    $image->bulkResize($this->resizes)
                );
            }
        }

        return $destination;
    }

    /**
     * Generates a unique file name based on the original file name and a unique identifier.
     *
     * @param string $fileName Original file name.
     * @return string Generated unique file name.
     */
    protected function generateUniqueFileName(string $fileName): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);

        // Normalize and transliterate the filename (optional, if you want readable names)
        if (function_exists('transliterator_transliterate')) {
            $baseName = transliterator_transliterate('Any-Latin; Latin-ASCII', $baseName);
        }

        // Replace non-alphanumeric characters with a hyphen
        $baseName = preg_replace('/[^a-zA-Z0-9]+/u', '-', $baseName);

        // Limit the length of the base name (50 characters) while keeping multibyte safety
        $baseName = mb_substr($baseName, 0, 50, 'UTF-8');

        // Ensure the file name is unique
        return sprintf(
            '%s.%s',
            uniqid("{$baseName}_"),
            $extension
        );
    }
}
