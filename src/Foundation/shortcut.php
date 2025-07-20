<?php

use Spark\Console\Commands;
use Spark\Container;
use Spark\Database\DB;
use Spark\Database\QueryBuilder;
use Spark\EventDispatcher;
use Spark\Exceptions\Http\InputValidationFailedException;
use Spark\Contracts\Support\Arrayable;
use Spark\Foundation\Application;
use Spark\Hash;
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
use Spark\Utils\Http;
use Spark\Utils\Image;
use Spark\Utils\Mail;
use Spark\Utils\Paginator;
use Spark\Utils\Uploader;
use Spark\Utils\Vite;
use Spark\View\View;

if (!function_exists('app')) {
    /**
     * Retrieve the application instance.
     *
     * This function returns the application instance, which is the top-level class
     * responsible for managing the application's lifecycle.
     *
     * @param string $abstract [optional] The abstract name or class name of the service or value to retrieve.
     *                          If not provided, the application instance is returned.
     *
     * @return mixed|Application The application instance or the resolved instance of the specified class or interface.
     */
    function app(?string $abstract = null): mixed
    {
        if ($abstract !== null) {
            return get($abstract);
        }

        return Application::$app;
    }
}

if (!function_exists('container')) {
    /**
     * Retrieve the application's dependency injection container.
     *
     * @return Container The dependency injection container instance.
     */
    function container(): Container
    {
        return app()->getContainer();
    }
}

if (!function_exists('call')) {
    /**
     * Call a method or function with the given parameters.
     *
     * This function allows you to call a method or function with the specified
     * parameters. It can be used to invoke methods on objects, static methods,
     * or even global functions.
     *
     * @param array|string|callable $abstract The method or function to call.
     * @param array $parameters The parameters to pass to the method or function.
     *
     * @return mixed The result of the method or function call.
     */
    function call(array|string|callable $abstract, array $parameters = []): mixed
    {
        return container()->call($abstract, $parameters);
    }
}

if (!function_exists('get')) {
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
}

if (!function_exists('has')) {
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
}

if (!function_exists('bind')) {
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
}

if (!function_exists('singleton')) {
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
}

if (!function_exists('request')) {
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
}

if (!function_exists('response')) {
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
}

if (!function_exists('json')) {
    /**
     * Create a JSON response with the given data.
     *
     * This function returns a JSON response with the provided data, status code,
     * flags, and depth. It uses the application's response helper to create the
     * JSON response.
     *
     * @param array $data The data to be included in the JSON response.
     * @param int $statusCode The HTTP status code for the response. Default is 200.
     * @param int $flags JSON encoding options. Default is 0.
     * @param int $depth The maximum depth to encode. Default is 512.
     *
     * @return Response The JSON response instance.
     */
    function json(array $data, int $statusCode = 200, int $flags = 0, int $depth = 512): Response
    {
        return response()->json($data, $statusCode, $flags, $depth);
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect to a specified URL.
     *
     * @param string $url The URL to redirect to.
     * @param int $httpCode The HTTP status code for the redirection. Default is 0.
     * @return Response The response instance after setting the redirect.
     */
    function redirect(string $url, int $httpCode = 0): Response
    {
        return response()->redirect($url, $httpCode);
    }
}

if (!function_exists('session')) {
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
}

if (!function_exists('router')) {
    /**
     * Get the current router instance.
     *
     * @return Router
     */
    function router(): Router
    {
        return get(Router::class);
    }
}

if (!function_exists('database')) {
    /**
     * Get the current database instance.
     *
     * @return DB The database instance.
     */
    function database(): DB
    {
        return get(DB::class);
    }
}

if (!function_exists('db')) {
    /**
     * Get the current database instance.
     *
     * @return DB The database instance.
     */
    function db(): DB
    {
        return get(DB::class);
    }
}

if (!function_exists('query')) {
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
}

if (!function_exists('external_db')) {
    /**
     *  Create a new QueryBuilder instance for an external database.
     *
     *  This function allows you to create a QueryBuilder instance that connects to an external database
     *  using the provided configuration array. The configuration should include the necessary parameters
     *  such as 'host', 'username', 'password', and 'database'.
     *
     *  @param array $config The configuration array for the external database connection.
     *  @return QueryBuilder The QueryBuilder instance connected to the external database.
     */
    function external_db(array $config): QueryBuilder
    {
        return new QueryBuilder(new DB($config));
    }
}

if (!function_exists('view')) {
    /**
     * Get the current view instance or render a template with the given context.
     *
     * If no arguments are provided, this function will return the current View instance.
     * If a template name is provided, this function will render the template with the given context
     * and return an instance of the Response class with the rendered HTML.
     *
     * @param string|null $template The path to the template file to render.
     * @param array $context An associative array of variables to pass to the template.
     * @return View|Response The View instance or the Response instance with the rendered HTML.
     */
    function view(?string $template = null, array $context = []): View|Response
    {
        // Return the current View instance.
        if ($template === null) {
            return get(View::class);
        }

        // Render the template with the given context.
        return get(Response::class)->write(
            get(View::class)
                ->render($template, $context) // Render the template.
        );
    }
}

if (!function_exists('fireline')) {
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
                'title' => $engine->yieldSection('title'),
            ])
                ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->setHeader('Pragma', 'no-cache')
                ->setHeader('Expires', '0');
        }

        // Otherwise, return a regular HTTP response with the rendered HTML
        return view($template, $context);
    }
}

if (!function_exists('url')) {
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
        $rootUrl = config('root_url', request()->getRootUrl());
        return rtrim($rootUrl . '/' . ltrim(str_replace('\\', '/', $path), '/'), '/');
    }
}

if (!function_exists('asset_url')) {
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
}

if (!function_exists('media_url')) {
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
}

if (!function_exists('request_url')) {
    /**
     * Get the URL of the current request.
     *
     * @return string The URL of the current request.
     */
    function request_url(): string
    {
        return request()->getUrl();
    }
}

if (!function_exists('route_url')) {
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
}

if (!function_exists('root_dir')) {
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
}

if (!function_exists('resource_dir')) {
    /**
     * Get the resources directory path with an optional appended path.
     *
     * This function returns the application's resources directory path, optionally
     * appending a specified sub-path to it. The resulting path is normalized
     * with a single trailing slash.
     *
     * @param string $path The sub-path to append to the resources directory path. Default is '/'.
     *
     * @return string The full path to the resources directory, including the appended sub-path.
     */
    function resource_dir(string $path = '/'): string
    {
        return root_dir('resources/' . ltrim($path, '/'));
    }
}

if (!function_exists('storage_dir')) {
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
}

if (!function_exists('lang_dir')) {
    /**
     * Get the language directory path with an optional appended path.
     *
     * This function returns the language directory path, optionally appending a
     * specified sub-path to it. The resulting path is normalized with a single
     * trailing slash.
     *
     * @param string $path The sub-path to append to the language directory path. Default is '/'.
     * @return string The full path to the language directory, including the appended sub-path.
     */
    function lang_dir(string $path = '/'): string
    {
        return dir_path(config('lang_dir') . '/' . ltrim($path, '/'));
    }
}

if (!function_exists('upload_dir')) {
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
}

if (!function_exists('dir_path')) {
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
}

// Helper/Utils Shortcut

if (!function_exists('dump')) {
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
}

if (!function_exists('dd')) {
    /**
     * Dump the given variable(s) with syntax highlighting and die.
     *
     * @param mixed ...$args The variable(s) to dump.
     *
     * @return never
     */
    function dd(...$args): never
    {
        // show the file and line number
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $file = $trace[0]['file']; // Get the file name
        $line = $trace[0]['line']; // Get the line number

        echo '<p style="color:#666;font-size: 14px;margin: 25px 25px 0 25px;font-style:italic;"><strong>// Dumped from:</strong> ' . $file . ':' . $line . '</p>';

        dump(...$args);

        die(0);
    }
}

if (!function_exists('config')) {
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
    function config(array|string $key, $default = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                app()->setEnv($k, $v);
            }
            return;
        }

        return app()->getEnv($key, $default);
    }
}

if (!function_exists('csrf_token')) {
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
}

if (!function_exists('csrf')) {
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
}

if (!function_exists('method')) {
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
}

if (!function_exists('auth')) {
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
}

if (!function_exists('can')) {
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
}

if (!function_exists('cannot')) {
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
}

if (!function_exists('authorize')) {
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
}

if (!function_exists('gate')) {
    /**
     * Manage the application's access control abilities.
     *
     * This function allows you to either access the Gate instance or define a new
     * ability. If an ability name and callback are provided, the ability is
     * registered. If only an ability name is provided, the ability is retrieved.
     * If no arguments are provided, the Gate instance is returned.
     *
     * @param string|null $ability The ability name to register or retrieve.
     * @param string|array|callable|null $callback The callback to use for the ability.
     *
     * @return Gate The Gate instance.
     */
    function gate(string|null $ability = null, string|array|callable|null $callback = null): Gate
    {
        $gate = get(Gate::class);

        if ($ability !== null && $callback !== null) {
            $gate->define($ability, $callback);
        }

        return $gate;
    }
}

if (!function_exists('event')) {
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
                if (is_array($v)) {
                    foreach ($v as $e) {
                        $event->addListener($k, $e);
                    }
                } else {
                    $event->addListener($k, $v);
                }
            }
        } elseif (is_string($eventName)) {
            // Dispatch the event with any additional arguments.
            $event->dispatch($eventName, ...$args);
        }

        return $event;
    }
}

if (!function_exists('job')) {
    /**
     * Create a new Job instance.
     *
     * This function creates a new Job instance with the given callback and optional arguments.
     * The callback is the function that will be executed when the job is processed.
     *
     * @param string|array|callable $callback The callback function to be executed when the job is processed.
     * @param mixed ...$config Additional arguments to pass to the Job constructor.
     * @return Job The new Job instance.
     */
    function job(string|array|callable $callback, ...$config): Job
    {
        return new Job($callback, ...$config);
    }
}

if (!function_exists('dispatch')) {
    /**
     * Dispatch a job with the given callback.
     *
     * This function creates a new job instance using the provided callback
     * and any additional arguments. The job is then dispatched to the queue
     * for processing.
     *
     * @param string|array|callable $callback The callback function to be executed by the job.
     * @param mixed ...$config Additional arguments to pass to the Job constructor.
     * @return void
     */
    function dispatch(string|array|callable $callback, ...$config): void
    {
        // Create a new job instance.
        $job = job($callback, ...$config);

        // Dispatch the job to the queue.
        $job->dispatch();
    }
}

if (!function_exists('is_guest')) {
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
}

if (!function_exists('user')) {
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
        return $key !== null && !is_guest() ? (auth()->getUser()->get($key, $default)) : auth()->getUser();
    }
}

if (!function_exists('cache')) {
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
}

if (!function_exists('unload_cache')) {
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
}

if (!function_exists('__')) {
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
}

if (!function_exists('vite')) {
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
}

if (!function_exists('input')) {
    /**
     * Retrieve and sanitize input data from the current request.
     *
     * This function fetches the input data from the current request and applies
     * the specified filter. The data is then passed through a sanitizer to ensure
     * it is safe for further processing.
     *
     * @param string|array $filter An optional array of filters to apply to the input data.
     * @param mixed $default The default value to return if the specified filter does not exist in the input data.
     * @return InputSanitizer|mixed An instance of the sanitizer.
     */
    function input(string|array $filter = [], $default = null): mixed
    {
        return request()->input($filter, $default);
    }
}

if (!function_exists('validator')) {
    /**
     * Validates the given data against a set of rules.
     *
     * @param string|array $rules An array of validation rules to apply.
     * @param array|null $data An optional array of data to validate.
     * @return InputSanitizer Returns a sanitizer object if validation passes.
     * @throws Exception Throws an exception if validation fails, with the first error message or a default message.
     */
    function validator(string|array $rules, ?array $data = null): InputSanitizer
    {
        $data ??= request()->all();

        $validator = get(InputValidator::class);
        $result = $validator->validate($rules, $data);

        if ($result) {
            return get(InputSanitizer::class)
                ->setData($result);
        }

        throw new InputValidationFailedException($validator->getFirstError() ?? 'Input validation failed');
    }
}

if (!function_exists('__e')) {
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
        return e(
            __($text, $arg, $args, $args2)
        );
    }
}

if (!function_exists('_e')) {
    /**
     * Escapes a translated string for safe HTML output.
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
    function _e(string $text, $arg = null, array $args = [], array $args2 = []): string
    {
        return e(
            __($text, $arg, $args, $args2)
        );
    }
}

if (!function_exists('cookie')) {
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
}

if (!function_exists('errors')) {
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
}

if (!function_exists('old')) {
    /**
     * Retrieve the old value of a given field from the previous request.
     *
     * @param string $field The field name to retrieve the old value for.
     * @param ?string $default The default value to return if the field does not exist.
     * @return string|null The old value of the field from the previous request, or the default value if not found.
     */
    function old(string $field, ?string $default = null): ?string
    {
        return request()->old($field, $default);
    }
}

if (!function_exists('mailer')) {
    /**
     * Send an email using the Mail utility class.
     *
     * This function sends an email using the Mail utility class. The parameters
     * are passed directly to the Mail utility class methods.
     *
     * @param null|string|array $to The recipient of the email
     * @param null|string $subject The subject of the email
     * @param null|string $content The content of the email
     * @param null|bool $isHtml Whether the content is HTML or plain text
     * @param null|string $template The template to use for the email
     * @param null|array $body The context to pass to the template
     * @param null|string|array $form The email address of the sender
     * @param null|string|array $reply The email address of the reply to
     * @return Mail The instance of the Mail utility class
     */
    function mailer(null|string|array $to = null, ?string $subject = null, ?string $body = null, ?bool $isHtml = null, ?string $template = null, ?array $context = null, null|string|array $from = null, null|string|array $reply = null): Mail
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
            $mailer->to(...array_values((array) $to));
        }

        if (isset($form)) {
            // Set the email address of the sender
            $mailer->mailer(...array_values((array) $form));
        }

        if (isset($reply)) {
            // Set the email address of the reply to
            $mailer->reply(...array_values((array) $reply));
        }

        return $mailer;
    }
}

if (!function_exists('abort')) {
    /**
     * Abort the current request with a given HTTP status code.
     *
     * @param string|int $error The error name of the error view or the HTTP status code.
     * @param string|null $message An optional message to display in the error view.
     * @param int|null $code The HTTP status code.
     *
     * @return void
     */
    function abort(string|int $error, ?string $message = null, ?int $code = null): void
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
}

if (!function_exists('command')) {
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
}

if (!function_exists('hashing')) {
    /**
     * Retrieves the Hash instance.
     *
     * This function returns the Hash instance, which provides methods
     * for hashing, validating, encrypting, and decrypting strings.
     *
     * @return Hash The Hash instance.
     */
    function hashing(): Hash
    {
        return get(Hash::class);
    }
}

if (!function_exists('http')) {
    /**
     * Retrieves the HTTP instance and optionally makes a request.
     *
     * This function fetches the HTTP instance and, if a URL is provided,
     * makes a request using the provided parameters and configuration.
     *
     * @param string|null $url Optional. The URL to make the request to.
     * @param array $params Optional. The query parameters for the request.
     * @param array $config Optional. The configuration for the request.
     * @return mixed The HTTP instance if no URL is provided, otherwise the response.
     */
    function http(?string $url = null, array $params = [], array $config = []): mixed
    {
        $http = get(Http::class);

        if ($url !== null) {
            $http->resetConfig($config);
            return $http->send($url, $params);
        }

        return $http;
    }
}

if (!function_exists('image')) {
    /**
     * Retrieves the Image instance and optionally creates a new image.
     *
     * This function fetches the Image instance and, if an image source is provided,
     * creates a new image using the provided image source.
     *
     * @param string $imageSource The source path of the image to be loaded.
     * @return Image The Image instance.
     */
    function image(string $imageSource): Image
    {
        return new Image($imageSource);
    }
}

if (!function_exists('paginator')) {
    /**
     * Retrieves the Paginator instance and optionally creates a new pagination.
     *
     * This function fetches the Paginator instance and, if parameters are provided,
     * creates a new pagination using the provided parameters.
     *
     * @param int $total The total number of items.
     * @param int $limit The number of items per page.
     * @param string $keyword The URL parameter keyword for the page.
     * @param array $data The data array for pagination.
     * @return Paginator The Paginator instance.
     */
    function paginator(int $total = 0, int $limit = 10, string $keyword = 'page', array $data = []): Paginator
    {
        $paginator = new Paginator($total, $limit, $keyword);

        $paginator->setData($data);

        return $paginator;
    }
}

if (!function_exists('uploader')) {
    /**
     * Retrieves the Uploader instance and optionally sets up a new upload.
     *
     * This function fetches the Uploader instance and, if parameters are provided,
     * sets up a new upload using the provided parameters.
     *
     * @param string|null $uploadTo Optional. The upload destination path. Default is null.
     * @param string|null $uploadDir Optional. The upload directory path. Default is the value of the 'upload_dir' configuration.
     * @param array $extensions Optional. The array of allowed file extensions. Default is an empty array.
     * @param int|null $maxSize Optional. The maximum allowed file size in bytes. Default is 2097152 (2MB).
     * @param array|null $resize Optional. The resize configuration array. Default is an empty array.
     * @param array|null $resizes Optional. The resizes configuration array. Default is an empty array.
     * @param int|null $compress Optional. The compression ratio for images. Default is null.
     * @return Uploader The Uploader instance.
     */
    function uploader(
        ?string $uploadTo = null,
        ?string $uploadDir = null,
        array $extensions = [],
        ?int $maxSize = 2097152,
        ?array $resize = null,
        ?array $resizes = null,
        ?int $compress = null
    ): Uploader {
        $uploader = new Uploader;

        $uploader->setup(
            uploadTo: $uploadTo,
            uploadDir: $uploadDir,
            extensions: $extensions,
            maxSize: $maxSize,
            resize: $resize,
            resizes: $resizes,
            compress: $compress
        );

        return $uploader;
    }
}

/**
 * Recursively converts any Arrayable objects and nested arrays into pure arrays.
 *
 * @param  mixed  $data  An Arrayable, an array of mixed values, or any other value.
 * @return mixed         A pure array if input was Arrayable/array; otherwise the original value.
 */
function toPureArray(mixed $data): mixed
{
    // If it's an object that knows how to cast itself to array, do it and recurse
    if ($data instanceof Arrayable) {
        return toPureArray($data->toArray());
    }

    // If it's an array, recurse into each element
    if (is_array($data)) {
        return array_map(
            /** @param mixed $item */
            fn($item): mixed => toPureArray($item),
            $data
        );
    }

    // Otherwise return as-is (string/int/etc)
    return $data;
}
