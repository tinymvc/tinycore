<?php

namespace Spark\Facades;

use Spark\View\Inertia as BaseInertia;

/**
 * Facade Inertia
 * 
 * This class serves as a facade for the Inertia view adapter, providing a static interface to the underlying Inertia class.
 * It allows easy access to Inertia rendering methods without needing to instantiate the Inertia class directly.
 * 
 * @method static void setRootView(string $view)
 * @method static void setVersion(string $version)
 * @method static void share(array $data)
 * @method static void composer(string|array $components, callable $composer)
 * @method static \Spark\Http\Response render(string $component, \Spark\Contracts\Support\Arrayable|array $props = [], array $headers = [])
 * @method static \Spark\Http\Response redirect(string $url, int $status = 302, array $headers = [])
 * @method static \Spark\Http\Response back(int $status = 302, array $headers = [])
 * @method static \Spark\Http\Response forceRefresh()
 * @method static \Spark\Helpers\LazyProp lazy(\Closure $callback)
 * @method static \Spark\Contracts\Support\Htmlable renderRootElement(string|array $page = '{}')
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Inertia extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseInertia::class;
    }
}