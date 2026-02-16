<?php

namespace Spark\View;

use Closure;
use Spark\Contracts\Support\Arrayable;
use Spark\Contracts\Support\Htmlable;
use Spark\Helpers\LazyProp;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Support\HtmlString;
use Spark\View\Contracts\InertiaAdapterContract;
use function in_array;
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
class Inertia implements InertiaAdapterContract
{
    /**
     * The root view that will be used to render the Inertia component. This view should include the necessary
     * JavaScript and HTML structure to handle Inertia.js on the client side.
     *
     * @var string
     */
    protected string $rootView = 'app';

    /**
     * The version string used for cache busting. This can be set to a value or generated dynamically
     * based on the component and props to ensure that clients receive the latest version of the component.
     *
     * @var string
     */
    protected string $version = '1.0';

    /**
     * An array of shared data that will be included in every Inertia response. This can be used to share
     * common props across all components, such as user information or application settings.
     *
     * @var array
     */
    protected static array $shared = [];

    /**
     * An array of view composers that can be used to modify the data passed to the view before rendering.
     * This allows for dynamic data manipulation based on the component being rendered or other factors.
     *
     * @var array
     */
    protected static array $composers = [];

    /**
     * Inertia constructor.
     *
     * @param Request $request The current request instance.
     */
    public function __construct(protected Request $request)
    {
        // Generate version based on manifest file content for cache busting
        $manifestPath = root_dir('public/build/.vite/manifest.json');
        if (is_file($manifestPath)) {
            $this->version = md5_file($manifestPath);
        }
    }

    /**
     * Set the root view for rendering Inertia components.
     *
     * @param string $view The name of the view to use as the root for Inertia rendering.
     * @return void
     */
    public function setRootView(string $view): void
    {
        $this->rootView = $view;
    }

    /**
     * Set the version string for cache busting.
     *
     * @param string $version The version string to use for cache busting.
     * @return void
     */
    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    /**
     * Share data across all Inertia responses.
     *
     * This method allows you to add data that will be included in every Inertia response. This is useful for sharing
     * common props such as user information, application settings, or any other data that should be available to all components.
     *
     * @param array $data An associative array of data to share across all Inertia responses.
     * @return void
     */
    public static function share(array $data): void
    {
        self::$shared = [...self::$shared, ...$data];
    }

    /**
     * Register a view composer for specific components.
     *
     * This method allows you to register a callable that will be executed when rendering a specific component or set of components.
     * The composer can modify the data passed to the view before rendering, allowing for dynamic data manipulation based on the component being rendered.
     *
     * @param string|array $components The name(s) of the component(s) to register the composer for. Use '*' to register for all components.
     * @param callable $composer The callable that will be executed when rendering the specified component(s). It receives the Inertia instance as an argument.
     * @return void
     */
    public static function composer(string|array $components, callable $composer): void
    {
        foreach ((array) $components as $component) {
            self::$composers[$component][] = $composer;
        }
    }

    /**
     * Create a lazy prop that is only evaluated during partial reloads.
     *
     * Lazy props are useful for expensive computations that should not be
     * included in the initial page load but can be loaded on demand.
     *
     * @param Closure $callback The callback that returns the prop value.
     * @return \Spark\Helpers\LazyProp The lazy prop instance.
     */
    public static function lazy(Closure $callback): LazyProp
    {
        return new LazyProp($callback);
    }

    /**
     * Render an Inertia component.
     *
     * This method checks if the request is an Inertia AJAX request. If it is, it returns a JSON response with the component data.
     * If it's not an AJAX request, it renders a view with the component data embedded in a 'page' variable.
     *
     * @param string $component The name of the Inertia component to render.
     * @param Arrayable|array $props An associative array of props to pass to the component.
     * @param array $headers Optional headers to include in the response.
     * @return Response The response instance containing the rendered component or JSON data.
     */
    public function render(string $component, Arrayable|array $props = [], array $headers = []): Response
    {
        // Run any registered composers for the component
        $this->runComposers($component);

        // Convert Arrayable props to arrays
        if ($props instanceof Arrayable) {
            $props = $props->toArray();
        }

        // Merge shared props with component props
        $props = [...self::$shared, ...$props];

        // Prepare the page data to be sent to the client
        $page = [
            'component' => $component,
            'props' => $props,
            'url' => $this->request->getUri(),
            'version' => $this->version
        ];

        // Check if this is an Inertia request, if not, render the root view with the page data
        if (!(bool) $this->request->header('X-Inertia')) {
            return view($this->rootView, compact('page'));
        }

        // Handle version mismatch - force full page reload
        if ($this->hasVersionMismatch()) {
            return $this->forceRefresh();
        }

        // Process props based on request type
        $page['props'] = $this->processProps($props, $this->isPartialReload($component));

        // If it's an Inertia AJAX request, return JSON
        return json($page)
            ->withHeaders(['X-Inertia' => 'true', 'Vary' => 'X-Inertia', ...$headers]);
    }

    /**
     * Force a full page refresh by returning 409 with X-Inertia-Location header.
     *
     * This is used when there's a version mismatch to ensure the client
     * reloads the page with fresh assets.
     *
     * @return Response The 409 response with location header.
     */
    public function forceRefresh(): Response
    {
        return response(statusCode: 409, headers: [
            'X-Inertia-Location' => $this->request->getUrl()
        ]);
    }

    /**
     * Process props based on whether this is a partial reload or initial load.
     *
     * - On initial load: Excludes lazy props, resolves regular closures
     * - On partial reload: Only includes requested props, resolves all including lazy
     *
     * @param array $props The props to process.
     * @param bool $isPartialReload Whether this is a partial reload.
     * @return array The processed props.
     */
    protected function processProps(array $props, bool $isPartialReload): array
    {
        $result = [];
        $partialOnly = $isPartialReload ? $this->getPartialData() : [];

        foreach ($props as $key => $value) {
            // For partial reloads, only include requested props
            if ($isPartialReload && !empty($partialOnly) && !in_array($key, $partialOnly)) {
                continue;
            }

            // Handle LazyProp instances
            if ($value instanceof LazyProp) {
                // Only include lazy props during partial reloads when explicitly requested
                if ($isPartialReload) {
                    $result[$key] = $value->resolve();
                }
                // Skip lazy props on initial load
                continue;
            }

            // Handle closures (always resolve them)
            if ($value instanceof Closure) {
                $result[$key] = $value();
                continue;
            }

            // If it's a Stringable or specific object, cast to string
            if (
                $value instanceof \Spark\Url ||
                $value instanceof \Spark\Utils\Carbon
            ) {
                $result[$key] = (string) $value;
                continue;
            }

            if ($value instanceof Arrayable) {
                $value = $value->toArray(); // Convert Arrayable to array for further processing
            }

            // Handle nested arrays recursively
            if (is_array($value)) {
                $result[$key] = $this->processNestedProps($value, $isPartialReload);
                continue;
            }

            // Regular values
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Process nested props within arrays.
     *
     * @param array $props The nested props array.
     * @param bool $isPartialReload Whether this is a partial reload.
     * @return array The processed nested props.
     */
    protected function processNestedProps(array $props, bool $isPartialReload): array
    {
        $result = [];

        foreach ($props as $key => $value) {
            // Handle LazyProp instances in nested arrays
            if ($value instanceof LazyProp) {
                if ($isPartialReload) {
                    $result[$key] = $value->resolve();
                }
                continue;
            }

            // Handle closures
            if ($value instanceof Closure) {
                $result[$key] = $value();
                continue;
            }

            // If it's a Stringable or specific object, cast to string
            if (
                $value instanceof \Spark\Url ||
                $value instanceof \Spark\Utils\Carbon
            ) {
                $result[$key] = (string) $value;
                continue;
            }

            if ($value instanceof Arrayable) {
                $value = $value->toArray(); // Convert Arrayable to array for further processing
            }

            // Handle deeper nested arrays
            if (is_array($value)) {
                $result[$key] = $this->processNestedProps($value, $isPartialReload);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Render the root element for Inertia.js.
     *
     * This method returns an HTML string that contains a div with the id "app" and a data-page attribute.
     * The data-page attribute will be populated with the JSON-encoded page data when the view is rendered.
     *
     * @return Htmlable An instance of Htmlable containing the root element HTML.
     */
    public function renderRootElement(string|array $page = '{}'): Htmlable
    {
        if (is_array($page)) {
            $page = json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return new HtmlString(
            sprintf('<div id="app" data-page="%s"></div>', htmlspecialchars($page, ENT_QUOTES, 'UTF-8'))
        );
    }

    /**
     * Create an Inertia redirect response.
     *
     * This method handles redirects according to Inertia.js conventions:
     * - Uses 303 status code for PUT/PATCH/DELETE requests to prevent browser confirmation dialogs
     * - Uses 409 status with X-Inertia-Location header for external redirects
     * - Supports custom status codes for specific redirect scenarios
     *
     * @param string $url The URL to redirect to.
     * @param int $status The HTTP status code (default: 302). Use 303 for form submissions, 301 for permanent redirects.
     * @param array $headers Additional headers to include in the response.
     * @return Response The redirect response instance.
     */
    public function redirect(string $url, int $status = 302, array $headers = []): Response
    {
        // For PUT, PATCH, DELETE requests, use 303 to prevent confirmation dialogs
        $method = strtoupper($this->request->getMethod());
        if (in_array($method, ['PUT', 'PATCH', 'DELETE']) && $status === 302) {
            $status = 303;
        }

        // Check if it's an external redirect (different domain)
        $isExternal = $this->isExternalUrl($url);

        // For external redirects with Inertia requests, use 409 with X-Inertia-Location header
        if ($isExternal && $this->request->header('X-Inertia')) {
            return response(statusCode: 409, headers: ['X-Inertia-Location' => $url, ...$headers]);
        }

        // Standard redirect - chain headers after redirect
        return redirect($url, $status)
            ->withHeaders($headers);
    }

    /**
     * Create an Inertia redirect back to the previous page.
     *
     * @param int $status The HTTP status code (default: 302).
     * @param array $headers Additional headers to include in the response.
     * @return Response The redirect response instance.
     */
    public function back(int $status = 302, array $headers = []): Response
    {
        $referer = $this->request->referer() ?: '/';

        return $this->redirect($referer, $status, $headers);
    }

    /**
     * Check if there is a version mismatch between client and server.
     *
     * @return bool True if versions don't match, false otherwise.
     */
    protected function hasVersionMismatch(): bool
    {
        $clientVersion = $this->request->header('X-Inertia-Version');

        // If no client version provided, no mismatch
        if ($clientVersion === null) {
            return false;
        }

        return $clientVersion !== $this->version;
    }

    /**
     * Check if this is a partial reload request for the given component.
     *
     * @param string $component The component being rendered.
     * @return bool True if this is a partial reload for this component.
     */
    protected function isPartialReload(string $component): bool
    {
        $partialComponent = $this->request->header('X-Inertia-Partial-Component');

        // Must have partial component header and it must match current component
        return $partialComponent !== null && $partialComponent === $component;
    }

    /**
     * Get the list of props requested in a partial reload.
     *
     * @return array List of prop names to include.
     */
    protected function getPartialData(): array
    {
        $partialData = $this->request->header('X-Inertia-Partial-Data');

        if ($partialData === null || $partialData === '') {
            return [];
        }

        return array_filter(explode(',', $partialData));
    }

    /**
     * Check if the given URL is external to the current application.
     *
     * @param string $url The URL to check.
     * @return bool True if the URL is external, false otherwise.
     */
    protected function isExternalUrl(string $url): bool
    {
        // If URL is relative or starts with /, it's internal
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return false;
        }

        // Parse the URL and compare root URLs (protocol + host)
        $urlRoot = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
        if ($port = parse_url($url, PHP_URL_PORT)) {
            $urlRoot .= ":$port";
        }

        $requestRoot = $this->request->getRootUrl();

        return $urlRoot !== $requestRoot;
    }

    /**
     * Run registered composers for a given component.
     *
     * This method checks if there are any composers registered for the specified component and executes them.
     * It also checks for wildcard composers registered for all components and executes them as well.
     *
     * @param string $component The name of the component to run composers for.
     * @return void
     */
    protected function runComposers(string $component): void
    {
        if (isset(self::$composers[$component])) {
            foreach (self::$composers[$component] as $composer) {
                $composer($this);
            }
        }

        // Run wildcard composers
        if (isset(self::$composers['*'])) {
            foreach (self::$composers['*'] as $composer) {
                $composer($this);
            }
        }
    }
}
