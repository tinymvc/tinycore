<?php

namespace Spark\Utils;

use GdImage;
use RuntimeException;

/**
 * Class Image
 * 
 * This class provides functionality to resize, compress, and rotate images (JPE, JPEG, and PNG).
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Image
{
    /**
     * @var resource $image The GD image resource.
     */
    private $image;

    /**
     * @var array $info Information about the image (dimensions, MIME type, etc.).
     */
    private array $info;

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
     * @throws RuntimeException If the GD extension is not loaded, or if the required functions are not enabled in this system.
     * @throws RuntimeException If the source image file does not exist.
     */
    public function setImageSource(string $imageSource)
    {
        $this->imageSource = $imageSource;

        // Clear the GD image resource
        unset($this->image);

        // Check is GD php extension is loaded or not.
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Extension: GD is required to create image');
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
                throw new RuntimeException("Required function: {$func}() is not found.");
            }
        }

        // Check if the source image file exist or not.
        if (!file_exists($this->imageSource)) {
            throw new RuntimeException("Image file: {$this->imageSource} does not exist.");
        }

        // Extract images information, size, dimensions, extension etc...
        $this->info = array_merge(
            getimagesize($this->imageSource),
            pathinfo($this->imageSource)
        );
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
     * Creates a GD image resource from the image file.
     * 
     * @return GdImage|resource The GD image resource.
     * 
     * @throws RuntimeException If the image type is unsupported.
     */
    public function getImage()
    {
        if (!isset($this->image)) {
            $this->image = match ($this->getInfo('mime')) {
                'image/jpeg', 'image/jpg' => imagecreatefromjpeg($this->imageSource),
                'image/png' => imagecreatefrompng($this->imageSource),
                default => throw new RuntimeException("Unsupported image type: {$this->imageSource}"),
            };
        }

        return $this->image;
    }

    /**
     * Compresses the image.
     * 
     * @param int $quality The compression quality (0-100 for JPEG, 0-9 for PNG).
     * @param string|null $destination The file path to save the compressed image.
     * 
     * @return bool Returns true on success, false on failure.
     */
    public function compress(int $quality = 75, $destination = null): bool
    {
        $destination ??= $this->imageSource;
        $image = $this->getImage();

        if (file_exists($destination)) {
            unlink($destination);
        }

        return match ($this->getInfo('mime')) {
            'image/png' => imagepng($image, $destination, round(9 * ($quality / 100))),
            default => imagejpeg($image, $destination, $quality),
        };
    }

    /**
     * Resizes the image to specified width and height.
     * 
     * @param int $imgWidth The desired width of the image.
     * @param int $imgHeight The desired height of the image.
     * @param string|null $destination The file path to save the resized image.
     * 
     * @return bool Returns true on success, false on failure.
     */
    public function resize(int $imgWidth, int $imgHeight, ?string $destination = null): bool
    {
        $destination ??= $this->imageSource;
        [$width, $height] = $this->getInfo();

        $image = $this->getImage();
        $aspectRatio = $width / $height;
        $imgAspectRatio = $imgWidth / $imgHeight;

        [$newWidth, $newHeight] = $aspectRatio >= $imgAspectRatio
            ? [$width / ($height / $imgHeight), $imgHeight]
            : [$imgWidth, $height / ($width / $imgWidth)];

        $photo = imagecreatetruecolor($imgWidth, $imgHeight);

        if ($this->getInfo('mime') === 'image/png') {
            imagealphablending($photo, false);
            imagesavealpha($photo, true);
            $transparent = imagecolorallocatealpha($photo, 255, 255, 255, 127);
            imagefilledrectangle($photo, 0, 0, $imgWidth, $imgHeight, $transparent);
        }

        if (file_exists($destination)) {
            unlink($destination);
        }

        imagecopyresampled(
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

        return match ($this->getInfo('mime')) {
            'image/png' => imagepng($photo, $destination),
            default => imagejpeg($photo, $destination),
        };
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
     * @param float $degrees The degree of rotation (clockwise).
     * 
     * @return bool Returns true on success, false on failure.
     */
    public function rotate(float $degrees): bool
    {
        $image = $this->getImage();

        if ($this->getInfo('mime') === 'image/png') {
            imagesavealpha($image, true);
        }

        $rotated = imagerotate($image, $degrees, 0);

        return match ($this->getInfo('mime')) {
            'image/png' => imagepng($rotated, $this->imageSource),
            default => imagejpeg($rotated, $this->imageSource),
        };
    }
}
