<?php

namespace Spark\View\Contracts;

use Spark\Http\Response;

/**
 * Interface InertiaAdapterContract
 * 
 * This interface defines the contract for an adapter that integrates Inertia.js with the Spark framework. It provides methods for rendering Inertia.js components 
 * and handling redirections in a way that is compatible with Inertia.js's expectations.
 * 
 * @since 2.2.0
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
interface InertiaAdapterContract
{
    /**
     * Render an Inertia.js component.
     *
     * @param string $component The name of the Inertia.js component to render.
     * @param array $props An associative array of props to pass to the component.
     * @param array $headers An associative array of headers to include in the response.
     * @return mixed The rendered component, which can be a Response or any other type depending on the implementation.
     */
    public function render(string $component, array $props = [], array $headers = []): mixed;

    /**
     * Redirect to a given URL.
     *
     * @param string $url The URL to redirect to.
     * @param int $status The HTTP status code for the redirection (default is 302).
     * @param array $headers An associative array of headers to include in the response.
     * @return Response A response object representing the redirection.
     */
    public function redirect(string $url, int $status = 302, array $headers = []): Response;
}