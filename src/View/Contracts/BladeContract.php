<?php

namespace Spark\View\Contracts;

/** Interface ViewContract */
interface BladeContract
{
    /**
     * Share data across all views.
     *
     * @param array|string $key
     * @param mixed $value
     * @return void
     */
    public static function share(array|string $key, mixed $value = null): void;

    /**
     * Register a view composer callback for one or more views.
     *
     * @param array|string $views
     * @param callable $composer
     * @return void
     */
    public static function composer(array|string $views, callable $composer): void;

    /**
     * Set the base path for views.
     *
     * @param string $path
     * @return self
     */
    public function setPath(string $path): self;

    /**
     * Get the base path for views.
     *
     * @return string
     */
    public function getPath(): string;

    /**
     * Set the path for compiled cache files.
     *
     * @param string $cachePath
     * @return self
     */
    public function setCachePath(string $cachePath): self;

    /**
     * Get the cache path for compiled views.
     *
     * @return string
     */
    public function getCachePath(): string;

    /**
     * Get the underlying compiler instance.
     *
     * @return BladeCompilerContract
     */
    public function getCompiler(): BladeCompilerContract;

    /**
     * Renders the specified template with the provided context.
     * If the request is AJAX-based, returns a JSON response.
     *
     * @param string $template The template file to render.
     * @param array $context Optional additional context.
     * @return string The rendered content or JSON response for AJAX requests.
     */
    public function render(string $template, array $context = []): string;

    /**
     * Renders a component template with the given context and outputs the rendered content.
     *
     * The function uses output buffering to capture the included template's output.
     * It extracts the context array into variables and makes the current object
     * available within the template as `$view`.
     *
     * @param string $component The component template file name.
     * @param array $context An associative array of variables to be extracted and used within the template.
     * @return string The rendered template content.
     */
    public function component(string $component, array $context = []): string;

    /**
     * Loads and renders the specified template file with the given context.
     *
     * @param string $template The template file name.
     * @param array $context Optional additional context to merge with the existing context.
     * @return string The rendered template content.
     */
    public function include(string $template, array $context = []): string;
}