<?php

use Spark\Console\Commands;
use Spark\Container;
use Spark\Database\DB;
use Spark\Database\QueryBuilder;
use Spark\EventDispatcher;
use Spark\Foundation\Application;
use Spark\Http\Auth;
use Spark\Http\Gate;
use Spark\Http\InputSanitizer;
use Spark\Http\InputValidator;
use Spark\Http\Request;
use Spark\Http\Response;
use Spark\Http\Session;
use Spark\Queue\Job;
use Spark\Router;
use Spark\Translator;
use Spark\Utils\Cache;
use Spark\Utils\Collect;
use Spark\Utils\Mail;
use Spark\Utils\Vite;
use Spark\View;

/**
 * Retrieve the application instance.
 *
 * This function returns the application instance, which is the top-level class
 * responsible for managing the application's lifecycle.
 *
 * @param string $abstract [optional] The abstract name or class name of the service or value to retrieve.
 *                          If not provided, the application instance is returned.
 *
 * @return Application The application instance or the resolved instance of the specified class or interface.
 */
function app(?string $abstract = null): Application
{
    if ($abstract !== null) {
        return get($abstract);
    }

    return Application::$app;
}

/**
 * Retrieve the application's dependency injection container.
 *
 * @return Container The dependency injection container instance.
 */
function container(): Container
{
    return app()->getContainer();
}

/**
 * Retrieve an instance of the given class or interface from the application container.
 * 
 * This function resolves and returns the instance of the specified class or interface
 * abstract from the application's dependency injection container.
 * 
 * @param string $abstract The class or interface name to resolve.
 * 
 * @return mixed The resolved instance of the specified class or interface.
 */
function get(string $abstract)
{
    return app()->get($abstract);
}

/**
 * Checks if a given abstract has a binding in the container.
 *
 * @param string $abstract The abstract name or class name of the service or value to be checked.
 * 
 * @return bool True if the abstract has a binding, false otherwise.
 */
function has(string $abstract): bool
{
    return app()->has($abstract);
}

/**
 * Registers a service provider with the application's dependency injection container.
 *
 * Bindings are registered with the container and returned on each request.
 *
 * @param string $abstract The abstract name or class name of the service to be resolved.
 * @param mixed $concrete The concrete value of the service to be resolved.
 */
function bind(string $abstract, $concrete = null): void
{
    app()->bind($abstract, $concrete);
}

/**
 * Registers a singleton service provider with the application's dependency injection container.
 *
 * Singleton bindings are registered with the container and returned on each request.
 * Once a singleton binding is registered, the same instance will be returned on each subsequent request.
 *
 * @param string $abstract The abstract name or class name of the service to be resolved.
 * @param mixed $concrete The concrete value of the service to be resolved.
 */
function singleton(string $abstract, $concrete = null): void
{
    app()->singleton($abstract, $concrete);
}

/**
 * Get the current request instance.
 *
 * @param string|string[] $key The key to retrieve from the request data.
 * @param mixed $default The default value to return if the key does not exist.
 *
 * @return Request|mixed The current request instance or the value of the specified key from the request data.
 */
function request($key = null, $default = null): mixed
{
    if ($key !== null) {
        // Retrieve the request input as an array.
        $input = get(Request::class)->all((array) $key);

        if (is_string($key)) {
            // Return the value of the specified key if it exists, otherwise the default.
            return $input[$key] ?? $default;
        }

        // Return the entire request input array.
        return $input;
    }

    // Return the current request instance.
    return get(Request::class);
}

/**
 * Get the current response instance or create a new one with provided data.
 *
 * This function returns the current response instance. If arguments are provided,
 * it creates a new response instance with those arguments.
 *
 * @param mixed $args Optional arguments to create a new response instance.
 * @return Response The response instance.
 */
function response(...$args): Response
{
    if (!empty($args)) {
        // Create and return a new Response with the provided arguments.
        return new Response(...$args);
    }

    // Return the existing Response instance from the container.
    return get(Response::class);
}

/**
 * Redirect to a specified URL.
 *
 * @param string $url The URL to redirect to.
 * @param bool $replace Whether to replace the current header. Default is true.
 * @param int $httpCode The HTTP status code for the redirection. Default is 0.
 */
function redirect(string $url, bool $replace = true, int $httpCode = 0): void
{
    response()->redirect($url, $replace, $httpCode);
}

/**
 * Manage session data by setting or retrieving values.
 *
 * This function can be used to set multiple session variables by passing an associative array,
 * or to retrieve a single session value by passing a string key.
 *
 * @param array|string|null $param An associative array for setting session data, a string key for retrieving a value, or null to return the session instance.
 * @param mixed $default The default value to return if the key does not exist.
 * @return Session|mixed The session instance, the value of the specified key, or the default value if the key does not exist.
 */
function session($param = null, $default = null): mixed
{
    $session = get(Session::class);

    if (is_array($param)) {
        // Set multiple session variables from the associative array.
        foreach ($param as $key => $value) {
            $session->set($key, $value);
        }
    } elseif ($param !== null) {
        // Retrieve a single session value by key.
        return $session->get($param, $default);
    }

    // Return the session instance.
    return $session;
}

/**
 * Get the current router instance.
 *
 * @return Router
 */
function router(): Router
{
    return get(Router::class);
}

/**
 * Get the current database instance.
 *
 * @return DB The database instance.
 */
function database(): DB
{
    return get(DB::class);
}

/**
 * Get the current database instance.
 *
 * @return DB The database instance.
 */
function db(): DB
{
    return get(DB::class);
}

/**
 * Create a new query instance.
 *
 * @param string $table The name of the table.
 *
 * @return QueryBuilder The query instance.
 */
function query(string $table): QueryBuilder
{
    return get(QueryBuilder::class)
        ->table($table);
}

/**
 * Render a view and return the response.
 *
 * @param string $template
 * @param array $context
 * @return Response
 */
function view(string $template, array $context = []): Response
{
    return get(Response::class)->write(
        get(View::class)
            ->render($template, $context)
    );
}

/**
 * Renders a template with the given context.
 *
 * This function will render a template with the given context using the
 * Template engine. If the request accepts JSON, it will return a JSON
 * response with the rendered HTML and title. Otherwise, it will return a
 * regular HTTP response with the rendered HTML.
 *
 * @param string $template The path to the template file to render.
 * @param array $context An associative array of variables to pass to the template.
 * @return Response The response object after writing the rendered content.
 */
function fireline(string $template, array $context = []): Response
{
    // Check if the request accepts JSON
    if (request()->isFirelineRequest()) {
        // Get the template engine
        $engine = get(View::class);

        // Return a JSON response with the rendered HTML and title
        return response()->json([
            'html' => $engine->render($template, $context),
            'title' => $engine->get('title'),
        ])
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Expires', '0');
    }

    // Otherwise, return a regular HTTP response with the rendered HTML
    return view($template, $context);
}

/**
 * Generate a URL from a given path.
 *
 * The path can be relative or absolute. If it is relative, it will be
 * resolved relative to the root URL of the application. If it is absolute,
 * it will be returned verbatim.
 *
 * @param string $path The path to generate a URL for.
 *
 * @return string The generated URL.
 */
function url(string $path = ''): string
{
    return rtrim(request()->getRootUrl() . '/' . ltrim(str_replace('\\', '/', $path), '/'), '/');
}

/**
 * Generate a URL from a given path relative to the asset directory.
 *
 * The path can be relative or absolute. If it is relative, it will be
 * resolved relative to the asset directory. If it is absolute,
 * it will be returned verbatim.
 *
 * @param string $path The path to generate a URL for.
 *
 * @return string The generated URL.
 */
function asset_url(string $path = ''): string
{
    $path = config('asset_url') . ltrim($path, '/');
    return strpos($path, '/', 0) === 0 ? url($path) : $path;
}

/**
 * Generate a URL from a given path relative to the media directory.
 *
 * The path can be relative or absolute. If it is relative, it will be
 * resolved relative to the media directory. If it is absolute,
 * it will be returned verbatim.
 *
 * @param string $path The path to generate a URL for.
 *
 * @return string The generated URL.
 */
function media_url(string $path = ''): string
{
    $path = config('media_url') . ltrim($path, '/');
    return strpos($path, '/', 0) === 0 ? url($path) : $path;
}

/**
 * Get the URL of the current request.
 *
 * @return string The URL of the current request.
 */
function request_url(): string
{
    return request()->getUrl();
}

/**
 * Generate a URL for a named route with an optional context.
 *
 * This function constructs a URL for a given named route, optionally
 * including additional context. The route name is resolved using the
 * application's router.
 *
 * @param string $name The name of the route to generate a URL for.
 * @param null|string|array $context Optional context to include in the route.
 *
 * @return string The generated URL for the specified route.
 */
function route_url(string $name, null|string|array $context = null): string
{
    return url(router()->route($name, $context));
}

/**
 * Get the application directory path with an optional appended path.
 *
 * This function returns the application's root directory path, optionally
 * appending a specified sub-path to it. The resulting path is normalized
 * with a single trailing slash.
 *
 * @param string $path The sub-path to append to the application directory path. Default is '/'.
 *
 * @return string The full path to the application directory, including the appended sub-path.
 */
function root_dir(string $path = '/'): string
{
    return dir_path(app()->getPath() . '/' . ltrim($path, '/'));
}

/**
 * Get the application storage directory path with an optional appended path.
 *
 * This function returns the application's storage directory path, optionally
 * appending a specified sub-path to it. The resulting path is normalized
 * with a single trailing slash.
 *
 * @param string $path The sub-path to append to the storage directory path. Default is '/'.
 *
 * @return string The full path to the storage directory, including the appended sub-path.
 */
function storage_dir(string $path = '/'): string
{
    return dir_path(config('storage_dir') . '/' . ltrim($path, '/'));
}

/**
 * Get the upload directory path with an optional appended path.
 *
 * This function returns the upload directory path, optionally appending a
 * specified sub-path to it. The resulting path is normalized with a single
 * trailing slash.
 *
 * @param string $path The sub-path to append to the upload directory path. Default is '/'.
 *
 * @return string The full path to the upload directory, including the appended sub-path.
 */
function upload_dir(string $path = '/'): string
{
    return dir_path(config('upload_dir') . '/' . ltrim($path, '/'));
}

/**
 * Returns the path to the given directory.
 *
 * This function takes a given path and returns the path to the directory
 * represented by that path. The path is trimmed of any trailing slashes and
 * the directory separator is normalized to the correct separator for the
 * current platform.
 *
 * @param string $path The path to the directory.
 * @return string The path to the directory.
 */
function dir_path(string $path): string
{
    return rtrim(str_replace(['//', '\\\\', '/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
}

// Helper/Utils Shortcut

/**
 * Dump the given variable(s) with syntax highlighting.
 *
 * @param mixed ...$args The variable(s) to dump.
 *
 * @return void
 */
function dump(...$args)
{
    if (php_sapi_name() !== 'cli') {
        echo '<pre style="font-size: 18px;margin: 25px;"><code>';
        ob_start();

        // Dump the given variable(s) to the output
        var_dump(...$args);

        // Get the output from the output buffer
        $output = highlight_string('<?php ' . ob_get_clean(), true);

        // Remove the <?php tag from the output
        echo str_replace('&lt;?php ', '', $output);

        // Close the <pre> and <code> tags
        echo '</code></pre>';
    } else {
        var_dump(...$args);
    }
}

/**
 * Dump the given variable(s) with syntax highlighting and die.
 *
 * @param mixed ...$args The variable(s) to dump.
 *
 * @return never
 */
function dd(...$args): never
{
    dump(...$args);
    die(0);
}

/**
 * Get the value of the specified environment variable.
 *
 * This function returns the value of the specified environment variable. If
 * the variable is not set, the given default value is returned instead.
 *
 * @param array|string $key The name of the environment variable to retrieve.
 * @param mixed $default The default value to return if the variable is not set.
 *
 * @return mixed The value of the specified environment variable, or the default
 * value if it is not set.
 */
function config(array|string $key, $default = null): mixed
{
    if (is_array($key)) {
        foreach ($key as $k => $v) {
            app()->setEnv($k, $v);
        }
    }

    return app()->getEnv($key, $default);
}

/**
 * Get the CSRF token.
 *
 * This function returns the CSRF token as a string. The CSRF token is a
 * random string that is generated when the application is booted. The CSRF
 * token is used to protect against cross-site request forgery attacks.
 *
 * @return string The CSRF token, or empty if no token has been generated yet.
 */
function csrf_token(): string
{
    return session('csrf_token', '');
}

/**
 * Generates a hidden form field containing the CSRF token.
 *
 * This function returns a string containing an HTML input field with the name
 * "_token" and the value of the CSRF token. The CSRF token is a random string
 * that is generated when the application is booted. It is used to protect
 * against cross-site request forgery attacks.
 *
 * @return string A string containing the CSRF token as a hidden form field.
 */
function csrf(): string
{
    return sprintf('<input type="hidden" name="_token" value="%s" />', csrf_token());
}

/**
 * Generates a hidden form field containing the specified HTTP method.
 *
 * This function returns a string containing an HTML input field with the name
 * "_method" and the value of the specified HTTP method. The resulting string
 * can be used in a form to simulate a different HTTP method than the one
 * specified in the form's "method" attribute.
 *
 * @param string $method The HTTP method to simulate.
 *
 * @return string A string containing the HTTP method as a hidden form field.
 */
function method(string $method): string
{
    return sprintf('<input type="hidden" name="_method" value="%s" />', strtoupper($method));
}

/**
 * Get the application's authentication manager.
 *
 * This function returns the application's authentication manager instance.
 * The authentication manager is responsible for authenticating users, and
 * managing the currently authenticated user.
 *
 * @return Auth The application's authentication manager.
 */
function auth(): Auth
{
    return get(Auth::class);
}

/**
 * Determine if the current user has a given ability.
 *
 * This function takes a string representing the ability to check, and
 * any additional arguments that may be required for the ability's closure.
 * It returns a boolean indicating whether the ability is allowed or not.
 *
 * @param string $ability The ability to check.
 * @param mixed  ...$arguments Additional arguments to pass to the ability's closure.
 *
 * @return bool Whether the ability is allowed or not.
 */
function can(string $ability, mixed ...$arguments): bool
{
    return get(Gate::class)
        ->allows($ability, ...$arguments);
}

/**
 * Determine if the current user does not have a given ability.
 *
 * This function takes a string representing the ability to check, and
 * any additional arguments that may be required for the ability's closure.
 * It returns a boolean indicating whether the ability is denied or not.
 *
 * @param string $ability The ability to check.
 * @param mixed  ...$arguments Additional arguments to pass to the ability's closure.
 *
 * @return bool Whether the ability is denied or not.
 */
function cannot(string $ability, mixed ...$arguments): bool
{
    return get(Gate::class)
        ->denies($ability, ...$arguments);
}

/**
 * Authorize the given ability.
 * 
 * This function takes a string representing the ability to authorize, and any
 * additional arguments that may be required for the ability's closure.
 * It throws an AuthorizationException if the ability is denied.
 * 
 * @param string $ability The ability to check.
 * @param mixed  ...$arguments Additional arguments to pass to the ability's closure.
 */
function authorize(string $ability, mixed ...$arguments): void
{
    get(Gate::class)
        ->authorize($ability, ...$arguments);
}

/**
 * Get the Gate instance.
 *
 * This function returns the Gate instance, which is responsible for
 * defining and checking the abilities of the current user.
 *
 * @param mixed ...$args Optional arguments to pass to the Gate instance.
 *    If present, the arguments are passed to the Gate::define() method.
 *
 * @return Gate The Gate instance.
 */
function gate(...$args): Gate
{
    $gate = get(Gate::class);

    if (!empty($args)) {
        $gate->define(...$args);
    }

    return $gate;
}

/**
 * Manage and dispatch events.
 *
 * This function allows you to either add event listeners or dispatch events.
 * If an array of event names and listeners is provided with no additional
 * arguments, it registers the listeners. If a single event name is provided
 * or additional arguments are given, it dispatches the event.
 *
 * @param array|string $eventName The event name(s) or listeners to be registered.
 * @param mixed ...$args Additional arguments to pass when dispatching an event.
 * @return EventDispatcher The event dispatcher instance.
 */
function event(null|array|string $eventName = null, ...$args): EventDispatcher
{
    $event = get(EventDispatcher::class);

    // If an array of events is provided with no additional arguments, add listeners.
    if (is_array($eventName) && empty($args)) {
        foreach ($eventName as $k => $v) {
            $event->addListener($k, $v);
        }
    } elseif (is_string($eventName)) {
        // Dispatch the event with any additional arguments.
        $event->dispatch($eventName, ...$args);
    }

    return $event;
}

/**
 * Create a new Job instance.
 *
 * This function creates a new Job instance with the given closure and optional arguments.
 * The closure is the function that will be executed when the job is processed.
 *
 * @param Closure $closure The closure function to be executed when the job is processed.
 * @param mixed ...$args Additional arguments to pass to the closure function.
 * @return Job The new Job instance.
 */
function job(Closure $closure, ...$args): Job
{
    return new Job($closure, ...$args);
}

/**
 * Dispatch a job with the given closure.
 *
 * This function creates a new job instance using the provided closure
 * and any additional arguments. The job is then dispatched to the queue
 * for processing.
 *
 * @param Closure $closure The closure function to be executed by the job.
 * @param mixed ...$args Additional arguments to pass to the closure function.
 * @return void
 */
function dispatch(Closure $closure, ...$args): void
{
    // Create a new job instance.
    $job = job($closure, ...$args);

    // Dispatch the job to the queue.
    $job->dispatch();
}

/**
 * Determine if the current request is made by a guest user.
 *
 * This function checks if the user is not set in the current application request,
 * indicating that the request is made by a guest (unauthenticated) user.
 *
 * @return bool True if the request is made by a guest user, false otherwise.
 */
function is_guest(): bool
{
    return auth()->isGuest();
}

/**
 * Get the currently authenticated user.
 *
 * If no user is authenticated, this function returns null. If a key is provided,
 * this function will return the value of the provided key from the user's data.
 * If the key does not exist in the user's data, the default value will be returned
 * instead.
 *
 * @param string $key The key to retrieve from the user's data.
 * @param mixed $default The default value to return if the key does not exist.
 *
 * @return mixed The user object, or the value of the provided key from the user's data.
 */
function user(?string $key = null, $default = null): mixed
{
    return $key !== null ? (auth()->getUser()->{$key} ?? $default) : auth()->getUser();
}

/**
 * Create a new collection instance.
 *
 * This function initializes a new collection object containing the given items.
 * The collection can be used to manipulate and interact with the array of items
 * using various collection methods.
 *
 * @param array $items The array of items to include in the collection.
 *
 * @return Collect A collection instance containing the provided items.
 */
function collect(array $items = []): Collect
{
    return get(Collect::class)->make($items);
}

/**
 * Retrieve or create a cache instance by name.
 *
 * This function returns an existing cache instance by the given name,
 * or creates a new one if it doesn't already exist. Cache instances
 * are stored globally and can be accessed using their names.
 *
 * @param string $name The name of the cache instance to retrieve or create. Default is 'default'.
 * @return Cache The cache instance associated with the specified name.
 */
function cache(string $name = 'default'): Cache
{
    global $caches;

    if (!isset($caches[$name])) {
        $caches[$name] = get(Cache::class)->setName($name);
    }

    return $caches[$name];
}

/**
 * Unloads a cache instance.
 *
 * This function unloads a cache instance by name. Once unloaded, the cache
 * will be removed from the global cache list and cannot be accessed again.
 *
 * @param string $name The name of the cache instance to unload. Default is 'default'.
 */
function unload_cache(string $name = 'default'): void
{
    global $caches;
    if (isset($caches[$name])) {
        $caches[$name]->unload();
        unset($caches[$name]);
    }
}

/**
 * Translates a given text using the application's translator service.
 *
 * This function wraps the translator's `translate` method, allowing
 * for text translation with optional pluralization and argument substitution.
 *
 * @param string $text The text to be translated.
 * @param $arg The number to determine pluralization or replace placeholder in the translated text.
 * @param array $args Optional arguments for replacing placeholders in the text.
 * @param array $args2 Optional arguments for replacing plural placeholders in the translated text.
 * 
 * @return string The translated text or original text if translation is unavailable.
 */
function __(string $text, $arg = null, array $args = [], array $args2 = []): string
{
    return get(Translator::class)
        ->translate($text, $arg, $args, $args2);
}

/**
 * Create a new Vite instance.
 *
 * This function initializes a new Vite instance with the given configuration.
 * The Vite instance provides a convenient interface for interacting with the
 * development server and production build processes.
 *
 * @param string|array $config The configuration for the Vite instance.
 *
 * @return Vite The Vite instance initialized with the given configuration.
 */
function vite(string|array $config = []): Vite
{
    $vite = get(Vite::class);
    if (func_num_args() > 0) {
        return $vite->configure($config);
    }

    return $vite;
}

/**
 * Retrieve and sanitize input data from the current request.
 *
 * This function fetches the input data from the current request and applies
 * the specified filter. The data is then passed through a sanitizer to ensure
 * it is safe for further processing.
 *
 * @param array $filter An optional array of filters to apply to the input data.
 * @return InputSanitizer|array An instance of the sanitizer or an array of input data.
 */
function input(array $filter = [], bool $sanitizer = true): mixed
{
    $data = request()->all($filter);

    if (!$sanitizer) {
        return $data;
    }

    return get(InputSanitizer::class)
        ->setData($data);
}

/**
 * Validates the given data against a set of rules.
 *
 * @param array $rules An array of validation rules to apply.
 * @param array|null $data An optional array of data to validate.
 * @return InputSanitizer Returns a sanitizer object if validation passes.
 * @throws Exception Throws an exception if validation fails, with the first error message or a default message.
 */
function validator(array $rules, ?array $data = null): InputSanitizer
{
    $data ??= request()->all();

    $validator = get(InputValidator::class);
    $result = $validator->validate($rules, $data);

    if ($result) {
        return get(InputSanitizer::class)
            ->setData($result);
    }

    throw new Exception($validator->getFirstError() ?? 'validation failed');
}

/**
 * Escapes a string for safe output in HTML by converting special characters to HTML entities.
 *
 * @param null|string $text The string to be escaped.
 * @return ?string The escaped string, safe for HTML output.
 */
function _e(?string $text): string
{
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Translates and escapes a given text for safe HTML output.
 *
 * This function first translates the provided text using the application's
 * translation service, with optional pluralization and argument substitution.
 * The translated text is then escaped to ensure it is safe for rendering
 * in HTML, converting special characters to HTML entities.
 *
 * @param string $text The text to be translated and escaped.
 * @param mixed $arg An optional argument for pluralization or placeholder replacement.
 * @param array $args Optional arguments for replacing placeholders in the text.
 * @param array $args2 Optional arguments for replacing plural placeholders in the translated text.
 *
 * @return string The translated and escaped text, safe for HTML output.
 */
function __e(string $text, $arg = null, array $args = [], array $args2 = []): string
{
    return _e(
        __($text, $arg, $args, $args2)
    );
}

/**
 * Retrieve or set a cookie value.
 *
 * This function allows you to either retrieve the value of a cookie by name,
 * or set a new cookie using an array of parameters. When setting a cookie,
 * the parameters should be passed in an array format compatible with setcookie().
 *
 * @param array|string $param The name of the cookie to retrieve, or an array of parameters to set a cookie.
 * @param mixed $default The default value to return if the cookie is not set and a string name is provided.
 * @return mixed The value of the cookie if retrieving, or the result of setcookie() if setting.
 */
function cookie(array|string $param, $default = null): mixed
{
    // Check if setting a cookie
    if (is_array($param)) {
        $values = array_values($param);
        $_COOKIE[$values[0]] = $values[1];

        // Set the cookie using the provided parameters
        return setcookie(...$param);
    }

    // Retrieve the cookie value or return the default value if not set
    return $_COOKIE[$param] ?? $default;
}

/**
 * Get the errors from the current request.
 *
 * @param null|array|string $field The field name to retrieve the error messages for.
 *                                  If null, all error object will be returned.
 * @return object|bool An object containing the error messages from the current request.
 */
function errors(null|array|string $field = null): mixed
{
    return request()->errors($field);
}

/**
 * Retrieve the old value of a given field from the previous request.
 *
 * @param string $field The field name to retrieve the old value for.
 * @param string $default The default value to return if the field does not exist.
 * @return string|null The old value of the field from the previous request, or the default value if not found.
 */
function old(string $field, string $default = null): ?string
{
    return request()->old($field, $default);
}

/**
 * Send an email using the Mail utility class.
 *
 * This function sends an email using the Mail utility class. The parameters
 * are passed directly to the Mail utility class methods.
 *
 * @param null|string $to The recipient of the email
 * @param null|string $subject The subject of the email
 * @param null|string $content The content of the email
 * @param null|bool $isHtml Whether the content is HTML or plain text
 * @param null|string $template The template to use for the email
 * @param null|array $body The context to pass to the template
 * @param null|string $form The email address of the sender
 * @param null|string $reply The email address of the reply to
 * @return Mail The instance of the Mail utility class
 */
function mailer(?string $to = null, ?string $subject = null, ?string $body = null, ?bool $isHtml = null, ?string $template = null, ?array $context = null, ?string $from = null, ?string $reply = null): Mail
{
    $mailer = get(Mail::class);

    if (isset($template)) {
        // Merge the context with the other parameters
        $context = array_merge((array) $context, compact('subject', 'to', 'from', 'reply'));
        // Set the email body content using the template
        $mailer->view($template, $context);
    }

    if (isset($body)) {
        // Set the email body content directly
        $mailer->body($body);
    }

    if (isset($isHtml)) {
        // Set whether the content is HTML or plain text
        $mailer->isHTML($isHtml);
    }

    if (isset($subject)) {
        // Set the subject of the email
        $mailer->subject($subject);
    }

    if (isset($to)) {
        // Set the recipient of the email
        $mailer->to($to);
    }

    if (isset($form)) {
        // Set the email address of the sender
        $mailer->mailer($form);
    }

    if (isset($reply)) {
        // Set the email address of the reply to
        $mailer->reply($reply);
    }

    return $mailer;
}

/**
 * Abort the current request with a given HTTP status code.
 *
 * @param string|int $error The error name of the error view or the HTTP status code.
 * @param int|null $code The HTTP status code.
 *
 * @return void
 */
function abort(string|int $error, ?int $code = null, ?string $message = null): void
{
    if ($code === null && is_int($error)) {
        // If the error is an integer, use it as the HTTP status code
        $code = $error;
    }

    $code ??= 500; // Default to 500 (Internal Server Error)

    // Clear the output buffer
    if (ob_get_length() > 0) {
        ob_end_clean();
    }

    // If the request is an AJAX request or the path starts with /api/, 
    // return a JSON response
    if (request()->expectsJson()) {
        // If the request is an AJAX request, return a JSON response
        response()
            ->json(['message' => $message ?? __e('Internal Server Error'), 'code' => $code], $code)
            ->send();

        exit; // Exit the script
    }

    // Get the view service
    $view = get(View::class);

    // Set the view path to the errors folder
    if (!$view->templateExists("errors/$error")) {
        $view->setPath(__DIR__ . '/resources/views');
    }

    // Set the error template
    $errorTemplate = "errors/$error";
    if (!$view->templateExists($errorTemplate)) {
        $errorTemplate = 'errors/error';
    }

    // Render the error view
    $viewHtml = $view->render($errorTemplate, compact('code', 'message'));

    // Send the response with the error view
    response($viewHtml, $code)
        ->send();

    exit; // Exit the script
}

/**
 * Retrieves the Commands instance and optionally adds a new command.
 *
 * This function fetches the Commands instance and, if arguments are provided,
 * adds a new command using the provided arguments.
 *
 * @param mixed ...$args Optional. Parameters for adding a new command.
 * @return Commands The Commands instance.
 */
function command(...$args): Commands
{
    // Retrieve the Commands instance
    $command = get(Commands::class);

    // If arguments are provided, add a new command
    if (!empty($args)) {
        $command->addCommand(...$args);
    }

    // Return the Commands instance
    return $command;
}
