<?php

namespace Spark\Utils;

use GdImage;
use Spark\Contracts\Utils\ImageContract;
use Spark\Exceptions\Utils\ImageException;
use Spark\Support\Traits\Macroable;
use function in_array;
use function sprintf;
use function strlen;

/**
 * Class Image
 * 
 * GD-based image utility supporting JPEG, PNG, GIF and WebP.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Image implements ImageContract
{
    use Macroable;

    /**
     * @var GdImage|null $image The GD image resource.
     */
    private GdImage|null $image = null;

    /**
     * @var array $info Information about the image (dimensions, MIME type, etc.).
     */
    private array $info = [];

    /** @param string|null $imageSource The source path of the image to be loaded, or null. */
    private ?string $imageSource = null;

    /**
     * Constructor for the Image class.
     */
    public function __construct(?string $imageSource = null)
    {
        $imageSource && $this->setImageSource($imageSource);
    }

    /**
     * Static factory method to create an instance of the Image class from a given image source.
     *
     * @param string $imageSource The source path of the image to be loaded.
     * @return static An instance of the Image class with the specified image source set.
     */
    public static function from(string $imageSource): static
    {
        if (preg_match('/^data:(image\/[a-z]+);base64,/', $imageSource)) {
            return static::fromBase64($imageSource);
        }

        return new static($imageSource);
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
     * @throws ImageException If the GD extension is not loaded, or if the required functions are not enabled in this system.
     * @throws ImageException If the source image file does not exist.
     */
    public function setImageSource(string $imageSource): void
    {
        $this->imageSource = $imageSource;

        if ($this->image instanceof GdImage) {
            $this->image = null;
        }

        // Check is GD php extension is loaded or not.
        if (!extension_loaded('gd')) {
            throw new ImageException('Extension: GD is required to create image');
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
                throw new ImageException("Required function: {$func}() is not found.");
            }
        }

        // Check if the source image file exist or not.
        if (!file_exists($this->imageSource)) {
            throw new ImageException("Image file: {$this->imageSource} does not exist.");
        }

        // Extract images information, size, dimensions, extension etc...
        $imageSize = @getimagesize($this->imageSource);

        if ($imageSize === false) {
            throw new ImageException("Failed to get image size for: {$this->imageSource}");
        }

        if (!isset($imageSize[0], $imageSize[1], $imageSize['mime'])) {
            throw new ImageException("Unable to read required image metadata for: {$this->imageSource}");
        }

        $this->info = [...$imageSize, ...pathinfo($this->imageSource)];
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
    public function getInfo(int|string|null $key = null, mixed $default = null): mixed
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
     * @return GdImage The GD image resource.
     *
     * @throws ImageException If the image type is unsupported.
     */
    public function getImage(): GdImage
    {
        if (!$this->image instanceof GdImage) {
            $this->image = match ($this->getInfo('mime')) {
                'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($this->imageSource),
                'image/png' => @imagecreatefrompng($this->imageSource),
                'image/gif' => @imagecreatefromgif($this->imageSource),
                'image/webp' => function_exists('imagecreatefromwebp')
                    ? @imagecreatefromwebp($this->imageSource)
                    : throw new ImageException('WebP is not supported by this GD build.'),
                default => throw new ImageException("Unsupported image type: {$this->getInfo('mime')}"),
            };

            if ($this->image === false) {
                throw new ImageException("Failed to create image from: {$this->imageSource}");
            }
        }

        return $this->image;
    }

    /**
     * Creates a GD image resource from the image file.
     *
     * @return GdImage The GD image resource.
     *
     * @throws ImageException If the image type is unsupported.
     */
    public function image(): GdImage
    {
        return $this->getImage();
    }

    /**
     * Compresses the image.
     *
     * @param int $quality The compression quality (0-100 for JPEG/WebP, 0-100 for PNG where higher is better).
     * @param string|null $destination The file path to save the compressed image.
     *
     * @return bool Returns true on success.
     *
     * @throws ImageException If quality is out of range.
     */
    public function compress(int $quality = 75, ?string $destination = null): bool
    {
        return $this->save($this->getImage(), $destination, quality: $quality);
    }

    /**
     * Resizes the image to specified width and height (cover fit, centered crop).
     *
     * @param int $imgWidth The desired width of the image.
     * @param int $imgHeight The desired height of the image.
     * @param string|null $destination The file path to save the resized image.
     *
     * @return bool Returns true on success.
     *
     * @throws ImageException If dimensions are invalid.
     */
    public function resize(int $imgWidth, int $imgHeight, ?string $destination = null): bool
    {
        if ($imgWidth <= 0 || $imgHeight <= 0) {
            throw new ImageException("Image dimensions must be positive, got: {$imgWidth}x{$imgHeight}");
        }

        if ($imgWidth > 10000 || $imgHeight > 10000) {
            throw new ImageException("Image dimensions too large (max 10000x10000): {$imgWidth}x{$imgHeight}");
        }

        $width = $this->width();
        $height = $this->height();

        $scale = max($imgWidth / $width, $imgHeight / $height);
        $scaledWidth = (int) round($width * $scale);
        $scaledHeight = (int) round($height * $scale);
        $destX = (int) floor(($imgWidth - $scaledWidth) / 2);
        $destY = (int) floor(($imgHeight - $scaledHeight) / 2);

        $canvas = $this->createCanvas($imgWidth, $imgHeight);

        if (!imagecopyresampled($canvas, $this->getImage(), $destX, $destY, 0, 0, $scaledWidth, $scaledHeight, $width, $height)) {
            throw new ImageException('Failed to resample image.');
        }

        return $this->save($canvas, $destination);
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
            $width = (int) $width;
            $height = (int) $height;

            if ($width <= 0 || $height <= 0) {
                throw new ImageException("Bulk resize dimensions must be positive: {$width}x{$height}");
            }

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
     * @param string|null $destination The file path to save the rotated image.
     *
     * @return bool Returns true on success.
     *
     * @throws ImageException If rotation fails.
     */
    public function rotate(float $degrees, ?string $destination = null): bool
    {
        $image = $this->getImage();
        $background = 0;

        if (in_array($this->mime(), ['image/png', 'image/webp', 'image/gif'], true)) {
            imagesavealpha($image, true);
            $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
        }

        $rotated = @imagerotate($image, $degrees, $background);

        if ($rotated === false) {
            throw new ImageException("Failed to rotate image by {$degrees} degrees.");
        }

        return $this->save($rotated, $destination);
    }

    public function width(): int
    {
        return (int) $this->getInfo(0, 0);
    }

    public function height(): int
    {
        return (int) $this->getInfo(1, 0);
    }

    public function mime(): ?string
    {
        return $this->getInfo('mime');
    }

    /**
     * Frees the cached GD resource.
     */
    public function destroy(): void
    {
        $this->image = null;
    }

    /**
     * Crops a rectangular region out of the image.
     */
    public function crop(int $x, int $y, int $width, int $height, ?string $destination = null): bool
    {
        $srcW = $this->width();
        $srcH = $this->height();

        if ($width <= 0 || $height <= 0 || $x < 0 || $y < 0 || $x + $width > $srcW || $y + $height > $srcH) {
            throw new ImageException("Crop area {$width}x{$height} at ({$x},{$y}) is outside the {$srcW}x{$srcH} image.");
        }

        $canvas = $this->createCanvas($width, $height);
        imagecopy($canvas, $this->getImage(), 0, 0, $x, $y, $width, $height);

        return $this->save($canvas, $destination);
    }

    /**
     * Scales the image to fit inside a box while keeping the aspect ratio.
     * Pass null for one side to constrain only width or only height.
     */
    public function scale(?int $maxWidth = null, ?int $maxHeight = null, bool $upscale = false, ?string $destination = null): bool
    {
        if ($maxWidth === null && $maxHeight === null) {
            throw new ImageException('Provide at least a max width or a max height.');
        }

        if (($maxWidth !== null && $maxWidth <= 0) || ($maxHeight !== null && $maxHeight <= 0)) {
            throw new ImageException('Scale dimensions must be positive.');
        }

        $width = $this->width();
        $height = $this->height();

        $ratio = min(
            $maxWidth !== null ? $maxWidth / $width : INF,
            $maxHeight !== null ? $maxHeight / $height : INF
        );

        if (!$upscale) {
            $ratio = min($ratio, 1);
        }

        $newW = max(1, (int) round($width * $ratio));
        $newH = max(1, (int) round($height * $ratio));

        $canvas = $this->createCanvas($newW, $newH);
        imagecopyresampled($canvas, $this->getImage(), 0, 0, 0, 0, $newW, $newH, $width, $height);

        return $this->save($canvas, $destination);
    }

    /**
     * Flips the image: 'horizontal', 'vertical' or 'both'.
     */
    public function flip(string $direction = 'horizontal', ?string $destination = null): bool
    {
        $mode = match (strtolower($direction)) {
            'horizontal', 'h' => IMG_FLIP_HORIZONTAL,
            'vertical', 'v' => IMG_FLIP_VERTICAL,
            'both' => IMG_FLIP_BOTH,
            default => throw new ImageException("Invalid flip direction: {$direction}"),
        };

        $image = $this->getImage();

        if (!imageflip($image, $mode)) {
            throw new ImageException('Failed to flip image.');
        }

        return $this->save($image, $destination);
    }

    /**
     * Fixes JPEG orientation using EXIF data (needs the exif extension).
     * Re-encoding drops the EXIF tag, so the fix is not applied twice.
     */
    public function autoOrient(?string $destination = null): bool
    {
        $orientation = 1;

        if ($this->getInfo('mime') === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($this->imageSource);
            $orientation = (int) ($exif['Orientation'] ?? 1);
        }

        if ($orientation <= 1 || $orientation > 8) {
            if ($destination !== null && $destination !== $this->imageSource && !@copy($this->imageSource, $destination)) {
                throw new ImageException("Failed to copy image to: {$destination}");
            }

            return true;
        }

        $image = $this->getImage();

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = imagerotate($image, 180, 0);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = imagerotate($image, -90, 0);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = imagerotate($image, -90, 0);
                break;
            case 7:
                $image = imagerotate($image, 90, 0);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = imagerotate($image, 90, 0);
                break;
        }

        if ($image === false) {
            throw new ImageException('Failed to auto-orient image.');
        }

        return $this->save($image, $destination);
    }

    /**
     * Converts the image to another format: jpg, png, gif or webp.
     * The original file is kept; this instance then points to the new file.
     */
    public function convert(string $format, int $quality = 90, ?string $destination = null): bool
    {
        $format = strtolower($format);

        $extension = match ($format) {
            'jpg', 'jpeg' => 'jpg',
            'png', 'gif', 'webp' => $format,
            default => throw new ImageException("Unsupported target format: {$format}"),
        };

        $destination ??= sprintf(
            '%s/%s.%s',
            $this->getInfo('dirname'),
            $this->getInfo('filename'),
            $extension
        );

        return $this->save($this->getImage(), $destination, $format, $quality);
    }

    public function grayscale(?string $destination = null): bool
    {
        return $this->applyFilter(IMG_FILTER_GRAYSCALE, [], $destination);
    }

    /**
     * @param int $level -255 (darker) to 255 (brighter)
     */
    public function brightness(int $level, ?string $destination = null): bool
    {
        return $this->applyFilter(IMG_FILTER_BRIGHTNESS, [max(-255, min(255, $level))], $destination);
    }

    /**
     * @param int $level -100 (less contrast) to 100 (more contrast).
     * GD's own scale is inverted, so the sign is flipped here.
     */
    public function contrast(int $level, ?string $destination = null): bool
    {
        return $this->applyFilter(IMG_FILTER_CONTRAST, [-max(-100, min(100, $level))], $destination);
    }

    public function invert(?string $destination = null): bool
    {
        return $this->applyFilter(IMG_FILTER_NEGATE, [], $destination);
    }

    public function blur(int $passes = 1, ?string $destination = null): bool
    {
        $image = $this->getImage();

        for ($i = 0; $i < max(1, $passes); $i++) {
            imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
        }

        return $this->save($image, $destination);
    }

    public function sharpen(?string $destination = null): bool
    {
        $image = $this->getImage();

        $matrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];

        if (!imageconvolution($image, $matrix, 8, 0)) {
            throw new ImageException('Failed to sharpen image.');
        }

        return $this->save($image, $destination);
    }

    public function sepia(?string $destination = null): bool
    {
        $image = $this->getImage();

        imagefilter($image, IMG_FILTER_GRAYSCALE);
        imagefilter($image, IMG_FILTER_COLORIZE, 90, 60, 30);

        return $this->save($image, $destination);
    }

    /**
     * Overlays a watermark image.
     *
     * @param string $position top-left|top|top-right|left|center|right|bottom-left|bottom|bottom-right
     * @param int $opacity 0-100
     */
    public function watermark(
        string $watermarkPath,
        string $position = 'bottom-right',
        int $opacity = 100,
        int $margin = 10,
        ?string $destination = null
    ): bool {
        if (!is_file($watermarkPath)) {
            throw new ImageException("Watermark file: {$watermarkPath} does not exist.");
        }

        if ($opacity < 0 || $opacity > 100) {
            throw new ImageException("Opacity must be between 0 and 100, got: {$opacity}");
        }

        $data = @file_get_contents($watermarkPath);
        $mark = $data !== false ? @imagecreatefromstring($data) : false;

        if ($mark === false) {
            throw new ImageException("Failed to load watermark: {$watermarkPath}");
        }

        imagepalettetotruecolor($mark);
        imagealphablending($mark, false);
        imagesavealpha($mark, true);

        if ($opacity < 100) {
            imagefilter($mark, IMG_FILTER_COLORIZE, 0, 0, 0, (int) round(127 * (1 - $opacity / 100)));
        }

        $image = $this->getImage();
        imagepalettetotruecolor($image);
        imagealphablending($image, true);

        $markW = imagesx($mark);
        $markH = imagesy($mark);

        [$x, $y] = $this->resolvePosition($position, imagesx($image), imagesy($image), $markW, $markH, $margin);

        imagecopy($image, $mark, max(0, $x), max(0, $y), 0, 0, $markW, $markH);

        return $this->save($image, $destination);
    }

    /**
     * Returns the image as base64, optionally as a data URI.
     */
    public function toBase64(bool $dataUri = true): string
    {
        $contents = @file_get_contents($this->imageSource);

        if ($contents === false) {
            throw new ImageException("Failed to read image: {$this->imageSource}");
        }

        $encoded = base64_encode($contents);

        return $dataUri ? "data:{$this->getInfo('mime')};base64,{$encoded}" : $encoded;
    }

    /**
     * Creates an Image instance from a base64 string.
     *
     * @param string $base64 The base64-encoded image data.
     * @param string|null $destination Optional destination path to save the image.
     * @return static An instance of the Image class with the specified image source set.
     *
     * @throws ImageException If the base64 data is invalid or cannot be decoded.
     */
    public static function fromBase64(string $base64, ?string $destination = null): static
    {
        if (preg_match('/^data:(image\/[a-z]+);base64,/', $base64, $matches)) {
            $base64 = substr($base64, strlen($matches[0]));
            $mime = $matches[1];
        } else {
            throw new ImageException('Invalid base64 image data.');
        }

        $data = base64_decode($base64);

        if ($data === false) {
            throw new ImageException('Failed to decode base64 image data.');
        }

        if ($destination === null) {
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => throw new ImageException("Unsupported MIME type: $mime"),
            };

            $destination = sys_get_temp_dir() . '/image_' . uniqid() . '.' . $extension;
        }

        if (@file_put_contents($destination, $data) === false) {
            throw new ImageException("Failed to write image to: {$destination}");
        }

        return new static($destination);
    }

    /**
     * Saves the image to a file.
     *
     * @param string $destination The file path to save the image.
     * @param string|null $format The desired image format (jpg, png, gif, webp).
     * @param int $quality The quality of the saved image (0-100).
     *
     * @return bool Returns true on success.
     */
    public function saveAs(string $destination, ?string $format = null, int $quality = 90): bool
    {
        return $this->save($this->getImage(), $destination, $format, $quality);
    }

    /**
     * Streams the image straight to the browser.
     * Swap this for your framework's Response class if you prefer.
     */
    public function output(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $this->getInfo('mime'));
        header('Content-Length: ' . filesize($this->imageSource));
        readfile($this->imageSource);
    }

    /**
     * Creates a blank truecolor canvas that keeps transparency.
     */
    private function createCanvas(int $width, int $height): GdImage
    {
        $canvas = @imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            throw new ImageException("Failed to create image with dimensions: {$width}x{$height}");
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }

    /**
     * Saves a GD image. Format comes from $format, then the destination
     * extension, then falls back to the source MIME type.
     */
    private function save(GdImage $image, ?string $destination = null, ?string $format = null, int $quality = 90): bool
    {
        if ($quality < 0 || $quality > 100) {
            throw new ImageException("Quality must be between 0 and 100, got: {$quality}");
        }

        $destination ??= $this->imageSource;
        $format = strtolower($format ?? pathinfo($destination, PATHINFO_EXTENSION));

        $format = match ($format) {
            'jpg', 'jpeg', 'jpe' => 'jpeg',
            'png', 'gif', 'webp' => $format,
            default => match ($this->getInfo('mime')) {
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpeg',
                },
        };

        // JPEG has no alpha channel, so flatten transparent sources onto white.
        if ($format === 'jpeg' && $this->getInfo('mime') !== 'image/jpeg') {
            $w = imagesx($image);
            $h = imagesy($image);
            $flat = imagecreatetruecolor($w, $h);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagealphablending($flat, true);
            imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);
            $image = $flat;
        }

        if ($format === 'png' || $format === 'webp') {
            imagesavealpha($image, true);
        }

        $result = match ($format) {
            'png' => @imagepng($image, $destination, (int) round(9 - (9 * ($quality / 100)))),
            'gif' => @imagegif($image, $destination),
            'webp' => function_exists('imagewebp')
                ? @imagewebp($image, $destination, $quality)
                : throw new ImageException('WebP is not supported by this GD build.'),
            default => @imagejpeg($image, $destination, $quality),
        };

        if ($result === false) {
            throw new ImageException("Failed to save image to: {$destination}");
        }

        $this->setImageSource($destination);

        return true;
    }

    /**
     * Applies a single GD filter and saves.
     */
    private function applyFilter(int $filter, array $args = [], ?string $destination = null): bool
    {
        $image = $this->getImage();

        if (!imagefilter($image, $filter, ...$args)) {
            throw new ImageException('Failed to apply image filter.');
        }

        return $this->save($image, $destination);
    }

    /**
     * Resolves the x/y of an overlay for a named position.
     */
    private function resolvePosition(string $position, int $baseW, int $baseH, int $w, int $h, int $margin): array
    {
        return match (strtolower($position)) {
            'top-left' => [$margin, $margin],
            'top' => [intdiv($baseW - $w, 2), $margin],
            'top-right' => [$baseW - $w - $margin, $margin],
            'left' => [$margin, intdiv($baseH - $h, 2)],
            'center' => [intdiv($baseW - $w, 2), intdiv($baseH - $h, 2)],
            'right' => [$baseW - $w - $margin, intdiv($baseH - $h, 2)],
            'bottom-left' => [$margin, $baseH - $h - $margin],
            'bottom' => [intdiv($baseW - $w, 2), $baseH - $h - $margin],
            'bottom-right' => [$baseW - $w - $margin, $baseH - $h - $margin],
            default => throw new ImageException("Invalid position: {$position}"),
        };
    }
}
