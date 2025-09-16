<?php

namespace Spark\Facades;

use Spark\View\Blade as BaseBlade;
use Spark\View\BladeCompiler;

/**
 * Facade Blade
 * 
 * This class serves as a facade for the Blade templating engine, providing a static interface to the underlying Blade class.
 * It allows easy access to templating methods such as rendering views and managing templates
 * without needing to instantiate the Blade class directly.
 * 
 * @method static BaseBlade setPath(string $path)
 * @method static BaseBlade setCachePath(string $cachePath)
 * @method static BaseBlade setUsePath(string $path, ?string $id = null)
 * @method static BladeCompiler getCompiler()
 * @method static string getPath()
 * @method static string getCachePath()
 * @method static string getTemplatePath(string $template)
 * @method static void share($key, $value = null)
 * @method static void composer($views, callable $composer)
 * @method static string render(string $template, array $context = [])
 * @method static string include(string $template, array $context = [])
 * @method static string component(string $component, array $context = [])
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Blade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseBlade::class;
    }
}
