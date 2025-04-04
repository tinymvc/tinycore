<?php

namespace Spark\Contracts;

/** Interface ViewContract */
interface ViewContract
{
    /**
     * Retrieves a value from the template context.
     *
     * @param string $key The context key to retrieve.
     * @param mixed $default The default value to return if $key is not set in the context.
     * @return mixed The value associated with the key, or the default value if the key is not set.
     */
    public function get(string $key, $default = null): mixed;

    /**
     * Assigns a key-value pair to the template context.
     *
     * @param string $key The context key.
     * @param mixed $value The value associated with the key.
     * @return self
     */
    public function set(string $key, mixed $value): self;

    /**
     * Checks if a key exists in the template context.
     *
     * @param string $key The context key to check.
     * @return bool True if the key exists in the context, false otherwise.
     */
    public function has(string $key): bool;

    /**
     * Sets the layout template.
     *
     * @param string $layout The layout template file name.
     * @return self
     */
    public function layout(string $layout): self;

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