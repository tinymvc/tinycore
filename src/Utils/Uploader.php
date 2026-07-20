<?php

namespace Spark\Utils;

use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Utils\UploaderUtilContract;
use Spark\Contracts\Utils\UploaderUtilDriverInterface;
use Spark\Exceptions\Utils\UploaderUtilException;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function strlen;

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
    use Macroable, Conditionable;

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
    public function __construct(
        null|string $uploadTo = null,
        null|string $uploadDir = null,
        null|array $extensions = [],
        null|bool $multiple = null,
        null|int $maxSize = 2048, // Default to 2MB
        null|float|array $resize = null,
        null|array $resizes = null,
        null|int $compress = null,
        null|UploaderUtilDriverInterface $driver = null
    ) {
        $extensions = $extensions === null ? [] : $extensions;

        $this->extensions = array_values(array_unique(array_filter(
            array_map(
                static fn(string $extension): string => ltrim(strtolower(trim($extension)), '.'),
                array_filter($extensions, 'is_string')
            ),
            static fn(string $extension): bool => $extension !== ''
        )));
        $this->multiple = $multiple;
        $this->maxSize = $maxSize;
        $this->compress = $compress;
        $this->driver = $driver;

        // Format the resize option to ensure it is an associative array with width as key and height as value
        $fmSize = fn($size) => is_array($size) && isset($size[0], $size[1])
            ? [$size[0] => $size[1]] : (is_numeric($size) ? [$size => $size] : $size);

        // Format the resize and resizes options
        $this->resize = $fmSize($resize);
        $this->resizes = collect($resizes)->mapWithKeys($fmSize(...))->all();

        $uploadDir ??= config('app.upload_dir');

        if ($uploadTo) {
            $uploadDir = dir_path("$uploadDir/$uploadTo");
        }

        $this->setUploadDir($uploadDir); // Set the upload directory
    }

    /**
     * Factory method to create a new instance of the Uploader class.
     *
     * @param null|string $uploadTo Upload directory path.
     * @param null|string $uploadDir Upload directory path.
     * @param array $extensions Supported file extensions.
     * @param null|bool $multiple Whether to support multiple file uploads.
     * @param null|int $maxSize Maximum file size (in KB).
     * @param null|array $resize Resize options for images.
     * @param null|array $resizes Bulk resize options for images.
     * @param null|int $compress Compression level for images.
     * @param null|UploaderUtilDriverInterface $driver File upload driver.
     * @return self Returns a new instance of the Uploader class.
     */
    public static function make(
        null|string $uploadTo = null,
        null|string $uploadDir = null,
        null|array $extensions = [],
        null|bool $multiple = null,
        null|int $maxSize = 2048, // Default to 2MB
        null|float|array $resize = null,
        null|array $resizes = null,
        null|int $compress = null,
        null|UploaderUtilDriverInterface $driver = null
    ): self {
        return new self(
            uploadTo: $uploadTo,
            uploadDir: $uploadDir,
            extensions: $extensions,
            multiple: $multiple,
            maxSize: $maxSize,
            resize: $resize,
            resizes: $resizes,
            compress: $compress,
            driver: $driver
        );
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

        if (!is_array($files) || !isset($files['name'])) {
            throw new UploaderUtilException(__('Invalid upload payload.'));
        }

        if (empty($files['name'])) {
            throw new UploaderUtilException(__('No files were provided.'));
        }

        $this->multiple ??= is_array($files['name']) && array_is_list((array) $files['name']);

        if (!$this->multiple && is_array($files['name'])) {
            throw new UploaderUtilException(__('Invalid upload payload for single upload.'));
        }

        if ($this->multiple) {
            $uploadedFiles = [];
            foreach ($files['name'] as $key => $name) {
                if (!array_key_exists($key, $files['tmp_name'])) {
                    continue;
                }

                $file = [
                    'name' => $name,
                    'type' => $files['type'][$key] ?? '',
                    'tmp_name' => $files['tmp_name'][$key],
                    'error' => $files['error'][$key] ?? 0,
                    'size' => $files['size'][$key] ?? 0,
                ];
                $uploadedFiles = [...$uploadedFiles, ...(array) $this->processUpload($file)];
            }
            return $uploadedFiles;
        } else {
            $uploadedFiles = $this->processUpload($files);
            if (is_array($uploadedFiles) && count($uploadedFiles) === 1) {
                return array_shift($uploadedFiles); // Return single file path if only one file is returned
            }

            return $uploadedFiles; // Return as array if multiple files are returned
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

        $baseDir = rtrim(str_replace('\\', '/', upload_dir()), '/');
        $normalizedPath = str_replace('\\', '/', $files);

        if (str_starts_with($normalizedPath, "$baseDir/")) {
            return ltrim(substr($normalizedPath, strlen("$baseDir/")), '/');
        }

        return $normalizedPath;
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
        if (!isset($file['tmp_name'], $file['name'])) {
            throw new UploaderUtilException(__('Invalid file data.'));
        }

        $tmpName = $file['tmp_name'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new UploaderUtilException(__('Upload error: %s', $file['error'] ?? UPLOAD_ERR_OK));
        }

        // Validate file size
        if (isset($this->maxSize) && ((int) ($file['size'] ?? 0) > ($this->maxSize * 1024))) {
            throw new UploaderUtilException(__('File size exceeds the maximum limit.'));
        }

        // Validate file extension
        $extension = strtolower(ltrim(pathinfo($file['name'], PATHINFO_EXTENSION), '.'));
        if (!empty($this->extensions) && !in_array(strtolower($extension), $this->extensions, true)) {
            throw new UploaderUtilException(__('Invalid file extension. Only %s are accepted.', implode(', ', $this->extensions)));
        }

        // Check if additional image options are set
        $additionalImageOptions = (isset($this->compress) || !empty($this->resize) || !empty($this->resizes)) &&
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif']);

        // Create a unique file name
        $filename = $this->generateUniqueFileName($file['name']);
        $destination = dir_path("{$this->uploadDir}/$filename");

        if ($additionalImageOptions) {
            // For image processing we need a local file first.
            if (!move_uploaded_file($tmpName, $destination)) {
                throw new UploaderUtilException(__('Failed to move uploaded file.'));
            }

            $destination = $this->processImageOptions($destination);

            if ($this->driver) {
                $uploadedFiles = [];

                try {
                    foreach ($destination as $filePath) {
                        if (!is_file($filePath)) {
                            throw new UploaderUtilException(__('Optimized image file not found: %s', $filePath));
                        }

                        if (!$this->handleUpload($filePath, $filePath)) {
                            throw new UploaderUtilException(__('Failed to upload file using driver.'));
                        }

                        $uploadedFiles[] = $filePath;
                    }

                    foreach ($destination as $filePath) {
                        if (!@unlink($filePath)) {
                            throw new UploaderUtilException(__('Failed to remove local temporary file: %s', $filePath));
                        }
                    }
                } catch (\Throwable $e) {
                    foreach ($destination as $filePath) {
                        if (is_file($filePath)) {
                            @unlink($filePath);
                        }
                    }

                    foreach ($uploadedFiles as $uploadedFile) {
                        $this->delete($uploadedFile);
                    }

                    throw new UploaderUtilException(__('Failed to upload file using driver: %s', $e->getMessage()), previous: $e);
                }
            }
        } elseif (!$this->driver) {
            if (!move_uploaded_file($tmpName, $destination)) {
                throw new UploaderUtilException(__('Failed to move uploaded file.'));
            }
        } elseif (!$this->handleUpload($tmpName, $destination)) {
            throw new UploaderUtilException(__('Failed to move uploaded file.'));
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
     * Apply image transform options and return all local file paths.
     *
     * @param string $destination Destination of the uploaded image.
     * @return array List of local image paths.
     */
    protected function processImageOptions(string $destination): array
    {
        $paths = [$destination];

        try {
            $image = new Image($destination);

            if (isset($this->compress)) {
                $image->compress($this->compress);
            }

            if (!empty($this->resize)) {
                $image->resize(
                    (int) array_key_first($this->resize),
                    (int) $this->resize[array_key_first($this->resize)]
                );
            }

            if (!empty($this->resizes)) {
                $paths = [...$paths, ...$image->bulkResize($this->resizes)];
            }

            return $paths;
        } catch (\Throwable $e) {
            if (is_file($destination)) {
                @unlink($destination);
            }

            foreach ($paths as $path) {
                if ($path !== $destination && is_file($path)) {
                    @unlink($path);
                }
            }

            throw new UploaderUtilException(
                __('Image processing failed: %s', $e->getMessage()),
                previous: $e
            );
        }
    }

    /**
     * Generates a unique file name based on the original file name and a unique identifier.
     *
     * @param string $fileName Original file name.
     * @return string Generated unique file name.
     */
    protected function generateUniqueFileName(string $fileName): string
    {
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);

        // Normalize and transliterate the filename (optional, if you want readable names)
        if (function_exists('transliterator_transliterate')) {
            $baseName = transliterator_transliterate('Any-Latin; Latin-ASCII', $baseName);
        }

        // Replace non-alphanumeric characters with a hyphen
        $baseName = preg_replace('/[^a-zA-Z0-9]+/u', '-', $baseName);

        // Limit the length of the base name (60 characters) while keeping multibyte safety
        $baseName = function_exists('mb_substr')
            ? mb_substr($baseName, 0, 60, 'UTF-8')
            : substr($baseName, 0, 60);

        if (trim($baseName) === '') {
            $baseName = 'upload';
        }

        // Ensure the file name is unique
        $uniqueSuffix = uniqid($baseName . '_', true);

        return $extension === ''
            ? $uniqueSuffix
            : "{$uniqueSuffix}.{$extension}";
    }
}
