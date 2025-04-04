<?php

namespace Spark;

use Spark\Contracts\ViewContract;
use Spark\Exceptions\UndefinedViewDirectoryPathException;
use Spark\Foundation\Application;
use Spark\Http\Request;
use Spark\Http\Session;

/**
 * Class View
 * 
 * Handles template rendering with optional layout
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class View implements ViewContract
{
    /**
     * Path to the directory containing template files
     * 
     * @var string
     */
    private string $path;

    /**
     * Stores data to be passed into templates for rendering
     * 
     * @var array
     */
    private array $context = [];

    /**
     * Optional layout template to wrap around the main template content
     * 
     * @var string
     */
    private string $layout;

    /**
     * Initializes the template path.
     *
     * @param string|null $path Optional base path for templates. Defaults to the application path.
     */
    public function __construct(?string $path = null)
    {
        $path ??= config('views_dir');

        if ($path === null) {
            throw new UndefinedViewDirectoryPathException('Views directory path is not set.');
        }

        $this->mergeContext([
            'app' => Application::$app,
            'request' => Application::$app->get(Request::class),
            'session' => Application::$app->get(Session::class),
            'errors' => Application::$app->get(Request::class)->getErrorObject(),
            'view' => $this,
        ]);

        $this->setPath($path);
    }

    /**
     * Sets the base path for templates.
     *
     * @param string $path The directory path for the template files.
     * @return self
     */
    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    /**
     * Assigns a key-value pair to the template context.
     *
     * @param string $key The context key.
     * @param mixed $value The value associated with the key.
     * @return self
     */
    public function set(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Retrieves a value from the template context.
     *
     * @param string $key The name of the value to retrieve.
     * @param mixed $default The default value to return if $key is not set in the context.
     * @return mixed Returns the value associated with the key, or the default value if the key is not set.
     */
    public function get(string $key, $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /**
     * Checks if a key exists in the template context.
     *
     * @param string $key The context key to check.
     * @return bool True if the key exists in the context, false otherwise.
     */
    public function has(string $key): bool
    {
        return isset($this->context[$key]);
    }

    /**
     * Retrieves the current template context.
     *
     * Returns an associative array of variables available to the template.
     *
     * @return array The template context.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Merges default data into the context for use in templates.
     *
     * Includes the application instance, current request object, session
     * object, error object, and the view instance.
     *
     * @param array $context Additional context data to merge.
     * @return void
     */
    public function mergeContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    /**
     * Removes specified keys from the template context.
     *
     * @param mixed ...$keys The context keys to remove.
     * @return self
     */
    public function remove(...$keys): self
    {
        foreach ($keys as $key) {
            unset($this->context[$key]);
        }

        return $this;
    }

    /**
     * Magic method to get a value from the template context.
     *
     * @param string $name The context key to retrieve.
     * @return mixed The value associated with the key, or null if not set.
     */
    public function __get($name)
    {
        return $this->get($name);
    }

    /**
     * Magic method to set a value in the template context.
     *
     * @param string $name The context key to set.
     * @param mixed $value The value to associate with the key.
     */
    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    /**
     * Magic method to check if a key exists in the template context.
     *
     * @param string $name The context key to check.
     * @return bool True if the key exists, false otherwise.
     */
    public function __isset($name)
    {
        return $this->has($name);
    }

    /**
     * Magic method to remove a key from the template context.
     *
     * @param string $name The context key to remove.
     */
    public function __unset($name)
    {
        $this->remove($name);
    }

    /**
     * Sets the layout template.
     *
     * @param string $layout The layout template file name.
     * @return self
     */
    public function layout(string $layout): self
    {
        $this->layout = $layout;
        return $this;
    }

    /**
     * Renders the specified template with the provided context.
     * If the request is AJAX-based, returns a JSON response.
     *
     * @param string $template The template file to render.
     * @param array $context Optional additional context.
     * @return string The rendered content or JSON response for AJAX requests.
     */
    public function render(string $template, array $context = []): string
    {
        $content = $this->include($template, $context);

        // Return a template part with layout.
        if (isset($this->layout)) {
            return $this->include($this->layout, ['content' => $content]);
        }

        // Returns only template part.
        return $content;
    }

    /**
     * Loads and renders the specified template file with the given context.
     *
     * @param string $template The template file name.
     * @param array $context Optional additional context to merge with the existing context.
     * @return string The rendered template content.
     */
    public function include(string $template, array $context = []): string
    {
        // Create a template location path with template root dir.
        $templatePath = $this->path . '/' . str_replace('.php', '', $template) . '.php';

        // Extract and pass variables from array.
        $context = array_merge($this->context, $context);

        return $this->renderTemplatePart($templatePath, $context);
    }

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
    public function component(string $component, array $context = []): string
    {
        $templatePath = $this->path . '/components/' . str_replace('.php', '', $component) . '.php';

        // Include template part and output as string.
        return $this->renderTemplatePart($templatePath, $context);
    }

    /**
     * Renders a template file with the given context and returns the rendered content as a string.
     *
     * The function uses output buffering to capture the included template's output.
     * It extracts the context array into variables and makes the current object
     * available within the template as `$view`.
     *
     * @param string $templatePath The full path to the template file.
     * @param array $context An associative array of variables to be extracted and used within the template.
     * @return string The rendered template content.
     */
    public function renderTemplatePart(string $templatePath, array $context = []): string
    {
        extract($context);

        // Include template part and return as string.
        ob_start();
        include dir_path($templatePath);
        return ob_get_clean();
    }

    /**
     * Checks if the specified template exists in the template root directory.
     *
     * @param string $template The template file name without extension.
     * @return bool True if the template file exists, otherwise false.
     */
    public function templateExists(string $template): bool
    {
        return file_exists($this->path . '/' . str_replace('.php', '', $template) . '.php');
    }
}
