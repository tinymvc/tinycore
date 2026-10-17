<?php

namespace Spark\Storage;

use Spark\Storage\Contracts\UploaderContract;
use Spark\Utils\File;
use Spark\Utils\Image;
use Spark\Contracts\Support\Arrayable;
use Spark\Storage\Contracts\UploaderDriverInterface;
use Spark\Exceptions\Utils\UploaderException;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
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
 * @package Spark\Storage
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Uploader implements UploaderContract
{
    use Macroable, Conditionable;

    /** @var string Upload directory path. */
    public string $uploadDir;

    /** @var null|array Supported file extensions. */
    public null|array $extensions = null;

    /** @var null|bool Whether to support multiple file uploads. */
    public null|bool $multiple = null;

    /** @var null|int Maximum file size (in KB). */
    public null|int $maxSize = null;

    /** @var null|array Resize options for images. */
    public null|array $resize = null;

    /** @var null|array Bulk resize options for images. */
    public null|array $resizes = null;

    /** @var null|int Compression level for images. */
    public null|int $compress = null;

    /** @var null|string Base directory stripped from driver destinations and returned paths. */
    private null|string $relativeTo = null;

    /** @var null|UploaderDriverInterface File upload driver. */
    private null|UploaderDriverInterface $driver = null;

    /**
     * Sets up the uploader configuration.
     *
     * @param null|string $uploadTo Upload directory path.
     * @param null|string $uploadDir Upload directory path.
     * @param null|array $extensions Supported file extensions.
     * @param null|bool $multiple Whether to support multiple file uploads.
     * @param null|int $maxSize Maximum file size (in KB).
     * @param null|array $resize Resize options for images.
     * @param null|array $resizes Bulk resize options for images.
     * @param null|int $compress Compression level for images.
     * @param null|UploaderDriverInterface $driver File upload driver.
     * @param null|string $relativeTo Base directory stripped from driver destinations and returned paths.
     */
    public function __construct(
        null|string $uploadTo = null,
        null|string $uploadDir = null,
        null|array $extensions = null,
        null|bool $multiple = null,
        null|int $maxSize = 2048, // Default to 2MB
        null|float|array $resize = null,
        null|array $resizes = null,
        null|int $compress = null,
        null|UploaderDriverInterface $driver = null,
        null|string $relativeTo = null
    ) {
        if ($extensions !== null && $extensions !== []) {
            $this->extensions = array_values(array_unique(array_filter(
                array_map(
                    static fn(string $extension): string => ltrim(strtolower(trim($extension)), '.'),
                    array_filter($extensions, 'is_string')
                ),
                static fn(string $extension): bool => $extension !== ''
            )));
        }

        $this->multiple = $multiple;
        $this->maxSize = $maxSize;
        $this->compress = $compress;
        $this->driver = $driver;
        $this->relativeTo = $relativeTo;

        // Format the resize option to ensure it is an associative array with width as key and height as value
        $fmSize = fn($size) => is_array($size) && isset($size[0], $size[1])
            ? [$size[0] => $size[1]] : (is_numeric($size) ? [$size => $size] : $size);

        // Format the resize and resizes options
        if ($resize !== null && $resize !== []) {
            $this->resize = $fmSize($resize);
        }

        if ($resizes !== null && $resizes !== []) {
            $this->resizes = collect($resizes)
                ->mapWithKeys($fmSize(...))
                ->all();
        }

        $uploadDir ??= config('app.upload_dir');
        $this->relativeTo ??= $uploadDir;

        if ($uploadTo !== null && $uploadTo !== '') {
            $uploadDir = dir_path("$uploadDir/$uploadTo");
        }

        $this->setUploadDir($uploadDir); // Set the upload directory
    }

    /**
     * Factory method to create a new instance of the Uploader class.
     *
     * @param null|string $uploadTo Upload directory path.
     * @param null|string $uploadDir Upload directory path.
     * @param null|array $extensions Supported file extensions.
     * @param null|bool $multiple Whether to support multiple file uploads.
     * @param null|int $maxSize Maximum file size (in KB).
     * @param null|array $resize Resize options for images.
     * @param null|array $resizes Bulk resize options for images.
     * @param null|int $compress Compression level for images.
     * @param null|string $relativeTo Base directory stripped from driver destinations and returned paths.
     * @param null|UploaderDriverInterface $driver File upload driver.
     * @return self Returns a new instance of the Uploader class.
     */
    public static function make(
        null|string $uploadTo = null,
        null|string $uploadDir = null,
        null|array $extensions = null,
        null|bool $multiple = null,
        null|int $maxSize = 2048, // Default to 2MB
        null|float|array $resize = null,
        null|array $resizes = null,
        null|int $compress = null,
        null|UploaderDriverInterface $driver = null,
        null|string $relativeTo = null
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
            driver: $driver,
            relativeTo: $relativeTo
        );
    }

    /**
     * Sets the upload directory.
     *
     * @param string $uploadDir Upload directory path.
     * @return $this
     * @throws UploaderException If the upload directory cannot be created or is not writable.
     */
    public function setUploadDir(string $uploadDir): self
    {
        // Ensure the upload directory exists and is writable
        if (!File::ensureDirectoryWritable($uploadDir)) {
            throw new UploaderException(__('Upload directory is not writable.'));
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
            $files = app(\Spark\Http\Request::class)->file($files);

            if ($files === null) {
                throw new UploaderException(__('No files were provided.'));
            }
        } elseif ($files instanceof Arrayable) {
            $files = $files->toArray();
        }

        $files = $this->normalizeFilesArray($files);

        if (!isset($files['name']) || $files['name'] === '' || $files['name'] === []) {
            throw new UploaderException(__('No files were provided.'));
        }

        // Local variable: auto-detection must not leak into the next upload() call.
        $multiple = $this->multiple ?? is_array($files['name']);

        if ($multiple !== is_array($files['name'])) {
            throw new UploaderException(__('Invalid upload payload.'));
        }

        if (!$multiple) {
            if (!is_string($files['name']) || !is_string($files['tmp_name'])) {
                throw new UploaderException(__('Invalid upload payload.'));
            }

            $paths = (array) $this->processUpload($files);

            return count($paths) === 1 ? reset($paths) : $paths;
        }

        if (
            !is_array($files['tmp_name'] ?? null)
            || array_keys($files['name']) !== array_keys($files['tmp_name'])
        ) {
            throw new UploaderException(__('Invalid multiple upload payload.'));
        }

        foreach (['error', 'size', 'type'] as $field) {
            if (isset($files[$field]) && !is_array($files[$field])) {
                throw new UploaderException(__('Invalid multiple upload payload.'));
            }
        }

        // Validate every shape before storing any files; runtime failures can still be partial.
        foreach ($files['name'] as $key => $name) {
            if (!is_string($name) || !is_string($files['tmp_name'][$key])) {
                throw new UploaderException(__('Invalid multiple upload payload.'));
            }
        }

        $uploaded = [];

        try {
            foreach ($files['name'] as $key => $name) {
                $error = (int) ($files['error'][$key] ?? UPLOAD_ERR_OK);

                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue; // empty slot in a multi-file input
                }

                $result = $this->processUpload([
                    'name' => $name,
                    'type' => $files['type'][$key] ?? '',
                    'tmp_name' => $files['tmp_name'][$key],
                    'error' => $error,
                    'size' => $files['size'][$key] ?? 0,
                ]);

                foreach ((array) $result as $path) {
                    $uploaded[] = $path;
                }
            }
        } catch (\Throwable $e) {
            if (!empty($uploaded)) {
                $this->delete($uploaded); // Rollback any successfully uploaded files
            }
            throw $e;
        }

        if ($uploaded === []) {
            throw new UploaderException(__('No files were provided.'));
        }

        return $uploaded;
    }

    /**
     * Normalizes the files array to the $_FILES-style structure:
     * either single (name => string, ...) or columnar (name => [...], tmp_name => [...], ...).
     *
     * Accepts a list of per-file arrays too (what Request::files() returns).
     */
    private function normalizeFilesArray(array $files): array
    {
        // List of per-file arrays -> columnar structure
        if (array_is_list($files) && isset($files[0]) && is_array($files[0]) && isset($files[0]['name'])) {
            $normalized = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];

            foreach ($files as $file) {
                if (!is_array($file) || !isset($file['name'])) {
                    continue;
                }

                $normalized['name'][] = $file['name'];
                $normalized['type'][] = $file['type'] ?? '';
                $normalized['tmp_name'][] = $file['tmp_name'] ?? '';
                $normalized['error'][] = $file['error'] ?? UPLOAD_ERR_NO_FILE;
                $normalized['size'][] = $file['size'] ?? 0;
            }

            return $normalized;
        }

        // Already columnar
        if (is_array($files['name'] ?? null)) {
            return $files;
        }

        // Single file
        return [
            'name' => $files['name'] ?? '',
            'type' => $files['type'] ?? '',
            'tmp_name' => $files['tmp_name'] ?? '',
            'error' => $files['error'] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'] ?? 0,
        ];
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
                $result = $this->delete($f) && $result;
            }
            return $result;
        }

        if ($this->driver) {
            return $this->driver->delete($file);
        }

        return (new LocalStorage($this->relativeTo ?? upload_dir()))->delete(StoragePath::normalize($file));
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

        $baseDir = rtrim(str_replace('\\', '/', $this->relativeTo ?? upload_dir()), '/');
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
     * @throws UploaderException If file validation fails or moving the file fails.
     */
    protected function processUpload(array $file): array|string
    {
        if (!isset($file['tmp_name'], $file['name']) || !is_string($file['name'])) {
            throw new UploaderException(__('Invalid file data.'));
        }

        $tmpName = $file['tmp_name'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new UploaderException(__('Upload error: %s', $file['error'] ?? UPLOAD_ERR_OK));
        }

        if (!is_string($tmpName) || !is_uploaded_file($tmpName)) {
            throw new UploaderException(__('The source is not a valid HTTP upload. Use Storage::putFile() for local files.'));
        }

        $actualSize = filesize($tmpName);
        if ($actualSize === false) {
            throw new UploaderException(__('Cannot read uploaded file size.'));
        }

        // Validate the actual size, not a caller-supplied size field.
        if (isset($this->maxSize) && ($actualSize > ($this->maxSize * 1024))) {
            throw new UploaderException(__('File size exceeds the maximum limit.'));
        }

        // Validate file extension
        $extension = strtolower(ltrim(pathinfo($file['name'], PATHINFO_EXTENSION), '.'));
        if (!empty($this->extensions) && !in_array(strtolower($extension), $this->extensions, true)) {
            throw new UploaderException(__('Invalid file extension. Only %s are accepted.', implode(', ', $this->extensions)));
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
                throw new UploaderException(__('Failed to move uploaded file.'));
            }

            $destination = $this->processImageOptions($destination);

            if ($this->driver) {
                $uploadedFiles = [];

                try {
                    foreach ($destination as $filePath) {
                        if (!is_file($filePath)) {
                            throw new UploaderException(__('Optimized image file not found: %s', $filePath));
                        }

                        if (!$this->handleUpload($filePath, $filePath)) {
                            throw new UploaderException(__('Failed to upload file using driver.'));
                        }

                        $uploadedFiles[] = $filePath;
                    }

                    foreach ($destination as $filePath) {
                        if (!@unlink($filePath)) {
                            throw new UploaderException(__('Failed to remove local temporary file: %s', $filePath));
                        }
                    }
                } catch (\Throwable $e) {
                    foreach ($destination as $filePath) {
                        if (is_file($filePath)) {
                            @unlink($filePath);
                        }
                    }

                    foreach ($uploadedFiles as $uploadedFile) {
                        try {
                            $this->delete($uploadedFile);
                        } catch (\Throwable) {
                            // Continue rollback and preserve the original upload error.
                            // A failed remote cleanup may require application reconciliation.
                        }
                    }

                    throw new UploaderException(__('Failed to upload file using driver: %s', $e->getMessage()), previous: $e);
                }
            }
        } elseif (!$this->driver) {
            if (!move_uploaded_file($tmpName, $destination)) {
                throw new UploaderException(__('Failed to move uploaded file.'));
            }
        } elseif (!$this->handleUpload($tmpName, $destination)) {
            throw new UploaderException(__('Failed to move uploaded file.'));
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

            throw new UploaderException(
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
        $baseName = trim(preg_replace('/[^a-zA-Z0-9]+/u', '-', $baseName), '-');

        // Limit the length of the base name (60 characters) while keeping multibyte safety
        $baseName = function_exists('mb_substr')
            ? mb_substr($baseName, 0, 60, 'UTF-8')
            : substr($baseName, 0, 60);

        if (trim($baseName) === '') {
            $baseName = 'upload';
        }

        // Ensure the file name is unique
        $uniqueSuffix = uniqid($baseName, true);

        return $extension === '' ? $uniqueSuffix : "$uniqueSuffix.$extension";
    }
}
