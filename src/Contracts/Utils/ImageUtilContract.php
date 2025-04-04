<?php

namespace Spark\Contracts\Utils;

/**
 * Interface for image utilities.
 */
interface ImageUtilContract
{
    /**
     * Compresses the image.
     *
     * @param int $quality The quality of the image (0-100 for JPEG, 0-9 for PNG).
     * @param string|null $destination The destination path to save the compressed image.
     *
     * @return bool Whether the image was compressed successfully.
     */
    public function compress(int $quality = 75, $destination = null): bool;

    /**
     * Resizes the image.
     *
     * @param int $imgWidth The target width of the image.
     * @param int $imgHeight The target height of the image.
     * @param string|null $destination The destination path to save the resized image.
     *
     * @return bool Whether the image was resized successfully.
     */
    public function resize(int $imgWidth, int $imgHeight, ?string $destination = null): bool;

    /**
     * Rotates the image.
     *
     * @param float $degrees The angle of rotation in degrees.
     *
     * @return bool Whether the image was rotated successfully.
     */
    public function rotate(float $degrees): bool;
}