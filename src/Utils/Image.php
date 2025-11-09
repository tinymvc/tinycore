<?php

namespace Spark\Utils;

use GdImage;
use Spark\Contracts\Utils\ImageUtilContract;
use Spark\Exceptions\Utils\ImageUtilException;
use Spark\Support\Traits\Macroable;

/**
 * Class Image
 * 
 * This class provides functionality to resize, compress, and rotate images (JPE, JPEG, and PNG).
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Image implements ImageUtilContract
{
    use Macroable;

    /**
     * @var GdImage|resource|null $image The GD image resource.
     */
    private GdImage|null $image = null;

    /**
     * @var array $info Information about the image (dimensions, MIME type, etc.).
     */
    private array $info = [];

    /**
     * Constructor for the image class.
     *
     * Initializes the image class by setting the image source if provided.
     * If an image source is specified, it will attempt to set it using the setImageSource method.
     *
     * @param string|null $imageSource The source path of the image to be loaded, or null.
     */
    public function __construct(private ?string $imageSource = null)
    {
        if ($this->imageSource !== null) {
            $this->setImageSource($this->imageSource);
        }
    }

    /**
     * Set the source of the image.
     *
     * This method sets the source of the image by checking if the GD extension
     * is loaded, and if the required functions are enabled in this system.
     * It also checks if the source image file exists or not, and extracts the
     * images information using the getimagesize and pathinfo functions.
     *
     * @param string $imageSource The source path of the image to be loaded.
     * @throws ImageUtilException If the GD extension is not loaded, or if the required functions are not enabled in this system.
     * @throws ImageUtilException If the source image file does not exist.
     */
    public function setImageSource(string $imageSource)
    {
        $this->imageSource = $imageSource;

        // Clear the GD image resource
        if (isset($this->image)) {
            imagedestroy($this->image);
            $this->image = null;
        }

        // Check is GD php extension is loaded or not.
        if (!extension_loaded('gd')) {
            throw new ImageUtilException('Extension: GD is required to create image');
        }

        // The extensions are required for this image object.
        $requiredFunctions = [
            'getimagesize',
            'imagecreatefromjpeg',
            'imagecreatefrompng',
            'imagejpeg',
            'imagecreatetruecolor',
            'imagecopyresampled',
            'imagecreatefromgif',
            'imagepng',
            'imagegif',
            'imagerotate'
        ];

        // Check if all extensions are enabled in this system.
        foreach ($requiredFunctions as $func) {
            if (!function_exists($func)) {
                throw new ImageUtilException("Required function: {$func}() is not found.");
            }
        }

        // Check if the source image file exist or not.
        if (!file_exists($this->imageSource)) {
            throw new ImageUtilException("Image file: {$this->imageSource} does not exist.");
        }

        // Extract images information, size, dimensions, extension etc...
        $imageSize = @getimagesize($this->imageSource);

        if ($imageSize === false) {
            throw new ImageUtilException("Failed to get image size for: {$this->imageSource}");
        }

        $this->info = array_merge($imageSize, pathinfo($this->imageSource));
    }

    /**
     * Sets the source of the image.
     * 
     * @param string $imageSource The source path of the image to be loaded.
     * 
     * @return static The current instance for method chaining.
     */
    public function set(string $imageSource): static
    {
        $this->setImageSource($imageSource);
        return $this;
    }

    /**
     * Sets the source of the image.
     * 
     * @param string $imageSource The source path of the image to be loaded.
     * 
     * @return static The current instance for method chaining.
     */
    public function source(string $imageSource): static
    {
        $this->setImageSource($imageSource);
        return $this;
    }

    /**
     * Retrieves information about the image.
     * 
     * @param string|null $key Specific information key to retrieve.
     * @param mixed $default Default value if the key is not found.
     * 
     * @return mixed The information value or an array of all information if no key is provided.
     */
    public function getInfo(?string $key = null, $default = null)
    {
        return $key !== null ? ($this->info[$key] ?? $default) : $this->info;
    }

    /**
     * Retrieves information about the image.
     * 
     * @param string|null $key Specific information key to retrieve.
     * @param mixed $default Default value if the key is not found.
     * 
     * @return mixed The information value or an array of all information if no key is provided.
     */
    public function info(?string $key = null, $default = null): mixed
    {
        return $this->getInfo($key, $default);
    }

    /**
     * Creates a GD image resource from the image file.
     * 
     * @return GdImage|resource The GD image resource.
     * 
     * @throws ImageUtilException If the image type is unsupported.
     */
    public function getImage()
    {
        if (!isset($this->image)) {
            $this->image = match ($this->getInfo('mime')) {
                'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($this->imageSource),
                'image/png' => @imagecreatefrompng($this->imageSource),
                'image/gif' => @imagecreatefromgif($this->imageSource),
                default => throw new ImageUtilException("Unsupported image type: {$this->getInfo('mime')}"),
            };

            if ($this->image === false) {
                throw new ImageUtilException("Failed to create image from: {$this->imageSource}");
            }
        }

        return $this->image;
    }

    /**
     * Creates a GD image resource from the image file.
     * 
     * @return GdImage|resource The GD image resource.
     * 
     * @throws ImageUtilException If the image type is unsupported.
     */
    public function image()
    {
        return $this->getImage();
    }

    /**
     * Compresses the image.
     * 
     * @param int $quality The compression quality (0-100 for JPEG/GIF, 0-100 for PNG where higher is better).
     * @param string|null $destination The file path to save the compressed image.
     * 
     * @return bool Returns true on success, false on failure.
     * 
     * @throws ImageUtilException If quality is out of range.
     */
    public function compress(int $quality = 75, $destination = null): bool
    {
        if ($quality < 0 || $quality > 100) {
            throw new ImageUtilException("Quality must be between 0 and 100, got: {$quality}");
        }

        $destination ??= $this->imageSource;
        $image = $this->getImage();

        if (file_exists($destination)) {
            if (!@unlink($destination)) {
                throw new ImageUtilException("Failed to delete existing file: {$destination}");
            }
        }

        // For PNG: Convert quality 0-100 to compression 0-9 (inverted: high quality = low compression)
        // quality 100 = compression 0 (no compression, best quality)
        // quality 0 = compression 9 (max compression, worst quality)
        $result = match ($this->getInfo('mime')) {
            'image/png' => @imagepng($image, $destination, (int) round(9 - (9 * ($quality / 100)))),
            'image/gif' => @imagegif($image, $destination),
            default => @imagejpeg($image, $destination, $quality),
        };

        if ($result === false) {
            throw new ImageUtilException("Failed to save compressed image to: {$destination}");
        }

        return $result;
    }

    /**
     * Resizes the image to specified width and height.
     * 
     * @param int $imgWidth The desired width of the image.
     * @param int $imgHeight The desired height of the image.
     * @param string|null $destination The file path to save the resized image.
     * 
     * @return bool Returns true on success, false on failure.
     * 
     * @throws ImageUtilException If dimensions are invalid.
     */
    public function resize(int $imgWidth, int $imgHeight, ?string $destination = null): bool
    {
        if ($imgWidth <= 0 || $imgHeight <= 0) {
            throw new ImageUtilException("Image dimensions must be positive, got: {$imgWidth}x{$imgHeight}");
        }

        if ($imgWidth > 10000 || $imgHeight > 10000) {
            throw new ImageUtilException("Image dimensions too large (max 10000x10000): {$imgWidth}x{$imgHeight}");
        }

        $destination ??= $this->imageSource;
        [$width, $height] = $this->getInfo();

        $image = $this->getImage();
        $aspectRatio = $width / $height;
        $imgAspectRatio = $imgWidth / $imgHeight;

        [$newWidth, $newHeight] = $aspectRatio >= $imgAspectRatio
            ? [$width / ($height / $imgHeight), $imgHeight]
            : [$imgWidth, $height / ($width / $imgWidth)];

        $photo = @imagecreatetruecolor($imgWidth, $imgHeight);

        if ($photo === false) {
            throw new ImageUtilException("Failed to create image with dimensions: {$imgWidth}x{$imgHeight}");
        }

        if ($this->getInfo('mime') === 'image/png') {
            imagealphablending($photo, false);
            imagesavealpha($photo, true);
            $transparent = imagecolorallocatealpha($photo, 255, 255, 255, 127);
            imagefilledrectangle($photo, 0, 0, $imgWidth, $imgHeight, $transparent);
        }

        if (file_exists($destination)) {
            if (!@unlink($destination)) {
                imagedestroy($photo);
                throw new ImageUtilException("Failed to delete existing file: {$destination}");
            }
        }

        $resampled = imagecopyresampled(
            $photo,
            $image,
            intval(0 - ($newWidth - $imgWidth) / 2),
            intval(0 - ($newHeight - $imgHeight) / 2),
            0,
            0,
            intval($newWidth),
            intval($newHeight),
            intval($width),
            intval($height)
        );

        if ($resampled === false) {
            imagedestroy($photo);
            throw new ImageUtilException("Failed to resample image");
        }

        $result = match ($this->getInfo('mime')) {
            'image/png' => @imagepng($photo, $destination),
            'image/gif' => @imagegif($photo, $destination),
            default => @imagejpeg($photo, $destination),
        };

        // Free memory
        imagedestroy($photo);

        if ($result === false) {
            throw new ImageUtilException("Failed to save resized image to: {$destination}");
        }

        return $result;
    }

    /**
     * Resizes the image to multiple sizes and saves them.
     * 
     * @param array $sizes An associative array of width => height sizes.
     * 
     * @return array An array of file paths where resized images are saved.
     */
    public function bulkResize(array $sizes): array
    {
        $saved = [];

        foreach ($sizes as $width => $height) {
            $savePath = sprintf(
                '%s/%s-%sx%s.%s',
                $this->getInfo('dirname'),
                $this->getInfo('filename'),
                $width,
                $height,
                $this->getInfo('extension')
            );

            if ($this->resize($width, $height, $savePath)) {
                $saved[] = $savePath;
            }
        }

        return $saved;
    }

    /**
     * Rotates the image by the specified degree.
     * 
     * @param float $degrees The degree of rotation (counter-clockwise).
     * 
     * @return bool Returns true on success, false on failure.
     * 
     * @throws ImageUtilException If rotation fails.
     */
    public function rotate(float $degrees): bool
    {
        $image = $this->getImage();

        if ($this->getInfo('mime') === 'image/png') {
            imagesavealpha($image, true);
        }

        $rotated = @imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            throw new ImageUtilException("Failed to rotate image by {$degrees} degrees");
        }

        $result = match ($this->getInfo('mime')) {
            'image/png' => @imagepng($rotated, $this->imageSource),
            'image/gif' => @imagegif($rotated, $this->imageSource),
            default => @imagejpeg($rotated, $this->imageSource),
        };

        // Update the cached image resource and free the old one
        if ($result !== false) {
            imagedestroy($this->image);
            $this->image = $rotated;
        } else {
            imagedestroy($rotated);
            throw new ImageUtilException("Failed to save rotated image to: {$this->imageSource}");
        }

        return $result;
    }

    /**
     * Destructor to clean up image resources.
     */
    public function __destruct()
    {
        if (isset($this->image)) {
            imagedestroy($this->image);
        }
    }
}
