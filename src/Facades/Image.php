<?php

namespace Spark\Facades;

use Spark\Utils\Image as BaseImage;

/**
 * Facade Image
 * 
 * This class serves as a facade for the Image system, providing a static interface to the underlying Image class.
 * It allows easy access to image manipulation methods such as resizing, cropping, and format conversion
 * without needing to instantiate the Image class directly.
 * 
 * @method static BaseImage source(string $imageSource)
 * @method static BaseImage set(string $imageSource)
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Image extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseImage::class;
    }
}
