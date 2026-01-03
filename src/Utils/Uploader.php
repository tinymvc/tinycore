<?php

namespace Spark\Utils;

use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Utils\UploaderUtilContract;
use Spark\Contracts\Utils\UploaderUtilDriverInterface;
use Spark\Exceptions\Utils\UploaderUtilException;
use Spark\Support\Traits\Macroable;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Class uploader
 *
 * Handles file uploads with options for validating file extensions, setting a maximum file size, 
 * supporting multiple files, resizing and compressing images, and storing files in a specific directory.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Uploader implements UploaderUtilContract
{
    use Macroable;

    /** @var string Upload directory path. */
    public string $uploadDir;

    /** @var array Supported file extensions.*/
    public array $extensions;

    /** @var null|bool Whether to support multiple file uploads. */
    public null|bool $multiple;

    /** @var null|int Maximum file size (in KB). */
    public null|int $maxSize;

    /** @var null|array Resize options for images. */
    public null|array $resize;

    /** @var null|array Bulk resize options for images. */
    public null|array $resizes;

    /** @var null|int Compression level for images. */
    public null|int $compress;

    /** @var ?UploaderUtilDriverInterface File upload driver. */
    private ?UploaderUtilDriverInterface $driver;

    /**
     * Sets up the uploader configuration.
     *
     * @param null|string $uploadTo Upload directory path.
     * @param null|string $uploadDir Upload directory path.
     * @param array $extensions Supported file extensions.
     * @param null|bool $multiple Whether to support multiple file uploads.
     * @param null|int $maxSize Maximum file size (in KB).
     * @param null|array $resize Resize options for images.
     * @param null|array $resizes Bulk resize options for images.
     * @param null|int $compress Compression level for images.
     */
    public function setup(
        null|string $uploadTo = null,
        null|string $uploadDir = null,
        null|array $extensions = [],
        null|bool $multiple = null,
        null|int $maxSize = 2048, // Default to 2MB
        null|array $resize = null,
        null|array $resizes = null,
        null|int $compress = null,
        null|UploaderUtilDriverInterface $driver = null
    ): self {
        $this->extensions = $extensions;
        $this->multiple = $multiple;
        $this->maxSize = $maxSize;
        $this->resize = isset($resize[0]) ? [$resize[0] => $resize[1]] : $resize;
        $this->resizes = $resizes;
        $this->compress = $compress;
        $this->driver = $driver;

        $uploadDir ??= config('upload_dir');

        if ($uploadTo) {
            $uploadDir = dir_path("$uploadDir/$uploadTo");
        }

        // Set the upload directory
        return $this->setUploadDir($uploadDir);
    }

    /**
     * Sets the upload directory.
     *
     * @param string $uploadDir Upload directory path.
     * @return $this
     * @throws UploaderUtilException If the upload directory cannot be created or is not writable.
     */
    public function setUploadDir(string $uploadDir): self
    {
        // Ensure the upload directory exists and is writable
        if (!fm()->ensureDirectoryWritable($uploadDir)) {
            throw new UploaderUtilException(__('Upload directory is not writable.'));
        }

        $this->uploadDir = dir_path($uploadDir);
        return $this;
    }

    /**
     * Uploads a file or multiple files.
     *
     * @param string|array|Arrayable $files The file(s) to upload.
     * @return string|array Returns the file path(s) of the uploaded file(s).
     */
    public function upload(string|array|Arrayable $files): string|array
    {
        if (is_string($files)) {
            $files = request()->file($files, []);
        } elseif ($files instanceof Arrayable) {
            $files = $files->toArray();
        }

        $this->multiple ??= is_array($files['name']) && count($files['name']) > 1;

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

    /**
     * Deletes a file or multiple files.
     *
     * @param string|array $file The file(s) to delete.
     * @return bool Returns true if the file(s) were successfully deleted, false otherwise.
     */
    public function delete(string|array $file): bool
    {
        $file = $this->removeUploadDir($file);

        if (is_array($file)) {
            $result = true;
            foreach ($file as $f) {
                $result = $result && $this->delete($f);
            }
            return $result;
        }

        if ($this->driver) {
            return $this->driver->delete($file);
        }

        return fm()->delete(upload_dir($file));
    }

    /**
     * Removes the upload directory path from a file path or an array of file paths.
     *
     * @param string|array $files The file path(s) to process.
     * @return string|array Returns the file path(s) without the upload directory.
     */
    public function removeUploadDir(string|array $files): string|array
    {
        if (is_array($files)) {
            return array_map($this->removeUploadDir(...), $files);
        }

        return str_replace([upload_dir(), '\\'], ['', '/'], $files);
    }

    /**
     * Create a copy of the uploader instance.
     *
     * @return self A new instance that is a copy of the current instance.
     */
    public function copy(): self
    {
        return clone $this;
    }

    /** @Add helpers methods for uploader object */

    /**
     * Processes the upload for a single file, including validation, file renaming, and optional resizing/compression.
     *
     * @param array $file Array containing file details such as name, type, tmp_name, error, and size.
     * @return array|string Returns the file path of the uploaded file or an array of paths if resizing options are applied.
     * @throws UploaderUtilException If file validation fails or moving the file fails.
     */
    protected function processUpload(array $file): array|string
    {
        // Validate file size
        if (isset($this->maxSize) && $file['size'] > ($this->maxSize * 1024)) {
            throw new UploaderUtilException(__('File size exceeds the maximum limit.'));
        }

        // Validate file extension
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!empty($this->extensions) && !in_array(strtolower($extension), $this->extensions)) {
            throw new UploaderUtilException(__('Invalid file extension. Only %s are accepted.', implode(', ', $this->extensions)));
        }

        // Check if additional image options are set
        $additionalImageOptions = (isset($this->compress) || isset($this->resize) || isset($this->resizes)) &&
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif']);

        // Create a unique file name
        $filename = $this->generateUniqueFileName($file['name']);
        $destination = dir_path("{$this->uploadDir}/$filename");

        // Move the uploaded file to the destination
        if ($this->driver && !$additionalImageOptions) {
            if (!$this->handleUpload($file['tmp_name'], $destination)) {
                throw new UploaderUtilException(__('Failed to move uploaded file.'));
            }
        } else {
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new UploaderUtilException(__('Failed to move uploaded file.'));
            }
        }

        // Compress, resize, and bulk resize image if options are set and the file is an image
        if ($additionalImageOptions) {
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
                $resizedImgs = $image->bulkResize($this->resizes);
                $destination = [$destination, ...$resizedImgs];
            }

            if ($this->driver) {
                foreach ((array) $destination as $k => $file) {
                    try {
                        $this->handleUpload($file, $file);
                        unlink($file); // Remove the original file after upload
                    } catch (\Exception $e) {
                        foreach ((array) $destination as $k2 => $file) {
                            if (is_file($file)) {
                                unlink($file);
                            }

                            // Delete the file if it was uploaded by the driver
                            if ($k > $k2) {
                                $this->delete($file);
                            }
                        }

                        // Throw an exception if the upload failed
                        throw new UploaderUtilException(__('Failed to upload file using driver: %s', $e->getMessage()));
                    }
                }
            }
        }

        return $this->removeUploadDir($destination);
    }

    /**
     * Handles the file upload process, either using a driver or the default PHP method.
     *
     * @param string $tmpName Temporary file name.
     * @param string $destination Destination path where the file should be moved.
     * @return bool Returns true if the upload was successful, false otherwise.
     */
    private function handleUpload(string $tmpName, string $destination): bool
    {
        if ($this->driver) {
            return $this->driver->upload($tmpName, $this->removeUploadDir($destination));
        }

        return move_uploaded_file($tmpName, $destination);
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

        // Limit the length of the base name (60 characters) while keeping multibyte safety
        $baseName = mb_substr($baseName, 0, 60, 'UTF-8');

        // Ensure the file name is unique
        return sprintf(
            '%s.%s',
            uniqid($baseName . '_', true),
            $extension
        );
    }
}
