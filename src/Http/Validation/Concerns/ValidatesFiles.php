<?php

namespace Spark\Http\Validation\Concerns;

use Psr\Http\Message\UploadedFileInterface;
use Spark\Utils\File;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function fclose;
use function filesize;
use function fwrite;
use function in_array;
use function is_array;
use function is_resource;
use function is_string;
use function is_uploaded_file;
use function ltrim;
use function strpos;
use function strtolower;
use function substr;
use function tmpfile;
use function stream_get_meta_data;
use const IMAGETYPE_AVIF;
use const IMAGETYPE_BMP;
use const IMAGETYPE_GIF;
use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;
use const IMAGETYPE_WEBP;
use const SEEK_END;
use const UPLOAD_ERR_OK;

trait ValidatesFiles
{
    /**
     * Validate image file
     *
     * Validates if a file is a valid image by checking its MIME type.
     *
     * @param mixed $file The uploaded file array containing 'tmp_name'.
     * @return bool True if the file is a valid image, false otherwise.
     */
    protected function validateImage(mixed $file): bool
    {
        if (!$this->isUploadedFile($file)) {
            return false;
        }

        // Use getimagesize for better validation
        $imageInfo = $this->inspectUploadedFile($file, static fn($path) => @getimagesize($path));
        if (!is_array($imageInfo)) {
            return false;
        }

        // Check for valid image MIME types
        $validImageTypes = [
            IMAGETYPE_JPEG,
            IMAGETYPE_PNG,
            IMAGETYPE_GIF,
            IMAGETYPE_WEBP,
            IMAGETYPE_BMP,
            IMAGETYPE_AVIF
        ];

        return in_array($imageInfo[2], $validImageTypes, true);
    }

    /**
     * Validate file MIME types
     *
     * Validates if a file's MIME type is in the allowed list.
     *
     * @param mixed $file The uploaded file array containing 'tmp_name'.
     * @param array $allowedMimes Array of allowed MIME types.
     * @return bool True if the file's MIME type is allowed, false otherwise.
     */
    protected function validateMimes(mixed $file, array $allowedMimes): bool
    {
        if (!$this->isUploadedFile($file)) {
            return false;
        }

        // Check if file has an allowed type list.
        if (empty($allowedMimes)) {
            return false;
        }

        $normalizedAllowedMimes = array_values(array_unique(array_filter(array_map(static function ($mime) {
            $normalized = strtolower((string) trim((string) ltrim((string) $mime, '.')));

            return $normalized !== '' ? $normalized : null;
        }, $allowedMimes))));
        if (empty($normalizedAllowedMimes)) {
            return false;
        }

        $mimeType = $this->inspectUploadedFile($file, static fn($path) => File::mimeType($path));

        if (!is_string($mimeType)) {
            return false;
        }

        $mimeType = strtolower((string) $mimeType);
        $slashPosition = strpos($mimeType, '/');
        $extension = $slashPosition === false ? '' : strtolower((string) substr($mimeType, $slashPosition + 1));

        return in_array($mimeType, $normalizedAllowedMimes, true)
            || in_array($extension, $normalizedAllowedMimes, true);
    }

    /**
     * Accept server-provided PSR uploads or files actually received by PHP.
     *
     * @param mixed $file
     * @return bool
     */
    protected function isUploadedFile(mixed $file): bool
    {
        if ($file instanceof UploadedFileInterface) {
            try {
                return $file->getError() === UPLOAD_ERR_OK && $file->getStream()->isReadable();
            } catch (\RuntimeException) {
                return false;
            }
        }

        return is_array($file) && ($file['error'] ?? null) === UPLOAD_ERR_OK
            && is_string($file['tmp_name'] ?? null) && is_uploaded_file($file['tmp_name']);
    }

    /**
     * Get the size of an uploaded file in bytes.
     *
     * @param mixed $file
     * @return int|null
     */
    protected function uploadedFileSize(mixed $file): ?int
    {
        if (is_array($file)) {
            $size = filesize($file['tmp_name']);
            return $size === false ? null : $size;
        }

        try {
            $stream = $file->getStream();
            if (($size = $stream->getSize()) !== null) {
                return $size;
            }
            if (!$stream->isSeekable()) {
                return null;
            }
            $position = $stream->tell();
            try {
                $stream->seek(0, SEEK_END);
                return $stream->tell();
            } finally {
                $stream->seek($position);
            }
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Inspect bytes without consuming or moving the upload; spool streams with bounded memory.
     *
     * @param mixed $file
     * @param callable $inspect
     * @return mixed
     */
    protected function inspectUploadedFile(mixed $file, callable $inspect): mixed
    {
        if (is_array($file)) {
            return $inspect($file['tmp_name']);
        }

        $temporary = null;
        try {
            $stream = $file->getStream();
            if (!$stream->isSeekable()) {
                return null;
            }
            $position = $stream->tell();
            try {
                $stream->rewind();
                $temporary = tmpfile();
                if ($temporary === false) {
                    return null;
                }
                while (!$stream->eof()) {
                    $chunk = $stream->read(8192);
                    if ($chunk === '' && !$stream->eof()) {
                        return null;
                    }
                    if (fwrite($temporary, $chunk) !== strlen($chunk)) {
                        return null;
                    }
                }
                return $inspect(stream_get_meta_data($temporary)['uri']);
            } finally {
                $stream->seek($position);
            }
        } catch (\RuntimeException) {
            return null;
        } finally {
            is_resource($temporary) && fclose($temporary);
        }
    }
}
