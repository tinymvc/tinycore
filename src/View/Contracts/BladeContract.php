<?php

namespace Spark\View\Contracts;

/** Interface ViewContract */
interface BladeContract
{
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