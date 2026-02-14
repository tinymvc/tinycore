<?php

namespace Spark\View;

use Spark\Contracts\Support\Htmlable;
use Spark\Foundation\Application;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Support\HtmlString;
use Spark\View\Contracts\IntertiaAdapterContract;
use function is_array;
use function sprintf;

/**
 * Inertia
 *
 * This class implements the IntertiaAdapterContract to provide a way to render Inertia.js components in a PHP application.
 * It handles both AJAX requests (returning JSON) and regular requests (rendering a view with the component data).
 * The root view can be set using the setRootView method, which defaults to 'app'.
 * 
 * @since 2.2.0
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Inertia implements IntertiaAdapterContract
{
    /**
     * The root view that will be used to render the Inertia component. This view should include the necessary
     * JavaScript and HTML structure to handle Inertia.js on the client side.
     *
     * @var string
     */
    protected static string $rootView = 'app';

    /**
     * Set the root view for rendering Inertia components.
     *
     * @param string $view The name of the view to use as the root for Inertia rendering.
     * @return void
     */
    public static function setRootView(string $view): void
    {
        self::$rootView = $view;
    }

    /**
     * Render an Inertia component.
     *
     * This method checks if the request is an Inertia AJAX request. If it is, it returns a JSON response with the component data.
     * If it's not an AJAX request, it renders a view with the component data embedded in a 'page' variable.
     *
     * @param string $component The name of the Inertia component to render.
     * @param array $props An associative array of props to pass to the component.
     * @param array $headers Optional headers to include in the response.
     * @return Response The response instance containing the rendered component or JSON data.
     */
    public static function render(string $component, array $props = [], array $headers = []): Response
    {
        /** @var \Spark\Http\Request $request The Application Request instance */
        $request = Application::$app->get(Request::class);

        // Generate a version string based on the component and props to help with caching
        $version = md5(
            implode('', array_keys($props)) . implode('', array_values($props))
        );

        // Prepare the page data to be sent to the client
        $page = [
            'component' => $component,
            'props' => $props,
            'url' => $request->getUri(),
            'version' => $version
        ];

        // If it's an Inertia AJAX request, return JSON
        if ($request->isAjax() && $request->header('X-Inertia')) {
            return response($page, 200, [
                'Content-Type' => 'application/json',
                'Vary' => 'Accept',
                'X-Inertia' => 'true',
                ...$headers
            ]);
        }

        // Otherwise, render the view with data-page attribute
        return view(self::$rootView, [
            'page' => json_encode($page, JSON_FORCE_OBJECT)
        ]);
    }

    /**
     * Render the root element for Inertia.js.
     *
     * This method returns an HTML string that contains a div with the id "app" and a data-page attribute.
     * The data-page attribute will be populated with the JSON-encoded page data when the view is rendered.
     *
     * @return Htmlable An instance of Htmlable containing the root element HTML.
     */
    public static function renderRootElement(string|array $page = '{}'): Htmlable
    {
        if (is_array($page)) {
            $page = json_encode($page, JSON_FORCE_OBJECT);
        }

        return new HtmlString(
            sprintf('<div id="app" data-page="%s"></div>', e($page))
        );
    }
}