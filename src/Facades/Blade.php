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
 * @method static BaseBlade usePath(string $path, ?string $id = null)
 * @method static BladeCompiler getCompiler()
 * @method static string getPath()
 * @method static string getCachePath()
 * @method static array getUsePath()
 * @method static string getTemplatePath(string $template)
 * @method static void share(array|string $key, mixed $value = null)
 * @method static void composer(array|string $views, callable $composer)
 * @method static string render(string $template, array $context = [])
 * @method static string include(string $template, array $context = [])
 * @method static string component(string $component, array $context = [])
 * @method static void directive(string $name, callable $handler)
 * @method static void clearCache()
 * @method static bool templateExists(string $template)
 * @method static bool exists(string $template)
 * @method static void startSection(string $name, ?string $content = null)
 * @method static void endSection()
 * @method static string yieldSection(string $name, string $default = '')
 * @method static string stopAndYieldSection()
 * @method static bool hasSection(string $name)
 * @method static ?string getCurrentSection()
 * @method static void flushState()
 * @method static void setExtends(string $template)
 * @method static \Spark\View\Attributes compileAttributes(array $attributes)
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
