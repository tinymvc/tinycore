<?php

namespace Spark\View\Contracts;

/**
 * IntertiaAdapterContract
 *
 * This interface defines the contract for rendering Inertia.js components in a PHP application.
 * It specifies a static method `render` that takes a component name, an array of props, and an array of headers,
 * and returns a response that can be sent back to the client.
 * 
 * @since 2.2.0
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
interface IntertiaAdapterContract
{
    public static function render(string $component, array $props = [], array $headers = []): mixed;
}