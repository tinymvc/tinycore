<?php

namespace Spark\Http;

use ArrayAccess;
use Spark\Contracts\Http\AuthContract;
use Spark\Contracts\Http\AuthDriverContract;
use Spark\Database\Model;
use Spark\Hash;
use Spark\Support\Traits\Macroable;
use Throwable;

/**
 * Class Auth
 * 
 * Handles user authentication and authorization for the TinyMvc framework.
 * This class provides the ability to log users in, log users out, and check if a user is logged in.
 *
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Auth implements AuthContract, ArrayAccess
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * @var ?Model The currently logged in user.
     */
    private ?Model $user;

    /** @var int The ID of the currently logged in user. */
    private int $id;

    /** @var self The singleton instance of the Auth class. */
    public static Auth $instance;

    /**
     * Constructor for the Auth class.
     *
     * Initializes the Auth instance with the specified session management, user model,
     * and optional configuration settings.
     *
     * @param Session $session The session instance used for managing user sessions.
     * @param string $userModel The fully qualified class name of the user model.
     * @param array $config Optional configuration array for customizing session key,
     *                      cache settings, and route redirections.
     */
    public function __construct(private Session $session, private string $userModel, private array $config = [])
    {
        self::$instance = $this; // Set the static instance to the current object

        $this->config = array_merge([
            'session_key' => 'user_id',
            'cache_enabled' => false,
            'cache_name' => 'auth_cache',
            'cache_expire' => '10 minutes',
            'guest_route' => 'login',
            'logged_in_route' => 'dashboard',
            'cookie_enabled' => true,
            'cookie_name' => 'auth',
            'cookie_expire' => '6 months',
            'driver' => null,
        ], $config);

        $this->checkAuthId(); // Check and set the authentication ID from the session
    }

    /**
     * Configures the Auth instance with additional settings.
     * 
     * This method allows you to modify the default configuration
     * for the Auth instance, such as session keys, cache settings,
     * and route redirections.
     * 
     * @param array $config An associative array of configuration settings.
     * @return void
     */
    public function configure(array $config): void
    {
        // Merge the provided configuration with the existing configuration
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Retrieves the currently logged in user.
     *
     * This method returns the currently authenticated user model if the user
     * is logged in, or null if no user is authenticated.
     *
     * @return ?Model The user model or null if no user is logged in.
     */
    public function getUser(): ?Model
    {
        if ($this->hasDriver()) {
            return $this->getDriver()->getUser(); // If a driver is set, use it to get the user
        }

        // Check if the user's ID is not set and the session has the session key
        if (!isset($this->user) && $this->hasId()) {
            // Attempt to load user from cache if caching is enabled
            if ($this->config['cache_enabled']) {
                $user = cache($this->config['cache_name'])
                    ->load(
                        key: $this->getId(),
                        callback: fn() => $this->userModel::find($this->getId()) ?: null,
                        expire: $this->config['cache_expire']
                    );
                unload_cache($this->config['cache_name']); // Unload cache after use
            } else {
                // Fetch user directly from the database if caching is not enabled
                $user = $this->userModel::find($this->getId()) ?: null;
            }

            if (isset($user, $user->id)) {
                $this->user = $user; // Set the user property if a valid user is found
            } else {
                $this->logout(); // Logout if the user is not found in the database
            }
        }

        // Return the currently logged in user
        return $this->user;
    }

    /**
     * Retrieves the ID of the currently logged in user.
     *
     * @return int The user ID or 0 if not logged in.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Checks if the current user has a valid ID (is logged in).
     *
     * @return bool True if the user has a valid ID, false otherwise.
     */
    public function hasId(): bool
    {
        return $this->id > 0;
    }

    /**
     * Retrieves the user model or a specific field from the user model.
     *
     * If a key is provided, it will return the value of that key from the user model.
     * If no key is provided, it will return the entire user model.
     *
     * @param string|null $key The key to retrieve from the user model, or null to return the entire model.
     * @param mixed $default The default value to return if the key does not exist.
     * @return mixed The user model or false if not logged in, or the value of the specified key.
     */
    public static function user(?string $key = null, $default = null): mixed
    {
        if ($key !== null && !self::$instance->isGuest()) {
            return self::$instance->getUser()->get($key, $default);
        }

        return self::$instance->getUser(); // Return the user model or false if not logged in
    }

    /**
     * Retrieves the ID of the currently logged in user.
     *
     * @return int The user ID or 0 if not logged in.
     */
    public static function id(): int
    {
        return self::$instance->getId();
    }

    /**
     * Attempts to authenticate a user with the given credentials.
     *
     * @param array $credentials An array containing the user's credentials (e.g., email and password).
     * @return bool True if authentication is successful, false otherwise.
     */
    public static function attempt(array $credentials): bool
    {
        $auth = auth();
        $identifier = \Spark\Support\Arr::except($credentials, ['password']);

        $user = $auth->userModel::where($identifier)->first();
        if ($user && hasher()->password($credentials['password'], $user->password)) {
            $auth->login($user);
            return true;
        }

        return false;
    }

    /**
     * Retrieves the route for guest users.
     *
     * This route is used to redirect users who are not logged in
     * to the appropriate login page or guest access endpoint.
     *
     * @return string The route path for guest users.
     */
    public function getGuestRoute(): string
    {
        return route_url($this->config['guest_route']);
    }

    /**
     * Retrieves the route for logged in users.
     *
     * This route is used to redirect users who are logged in
     * to the appropriate admin dashboard or login success endpoint.
     *
     * @return string The route path for logged in users.
     */
    public function getLoggedInRoute(): string
    {
        return route_url($this->config['logged_in_route']);
    }

    /**
     * Checks if the current user is a guest (not logged in).
     *
     * @return bool True if the user is a guest, false otherwise.
     */
    public function isGuest(): bool
    {
        return !$this->isLoggedIn();
    }

    /**
     * Checks if the current user is logged in.
     *
     * @return bool True if the user is logged in, false otherwise.
     */
    public function isLoggedIn(): bool
    {
        return $this->hasId() && $this->getUser() !== null;
    }

    /**
     * Logs in the specified user by setting the session and user properties.
     *
     * @param Model $user The user model to be logged in.
     * @param bool $remember Optional parameter to determine if the user should be remembered.
     * @return void
     */
    public function login(Model $user, bool $remember = false): void
    {
        if ($this->hasDriver()) {
            $this->getDriver()->login($user, $remember);
            return; // If a driver is set, use it to handle login
        }

        $this->session->set($this->config['session_key'], $user->id);
        $this->user = $user;
        $this->id = $user->id;

        if ($remember && isset($this->config['cookie_name'])) {
            // set cookie expiration time
            $tokenExpire = strtotime($this->config['cookie_expire'] ?? '1 year');

            // add user hashed token in cookie with expiration
            $token = encrypt(json_encode(['id' => $user->id, 'expire' => $tokenExpire]));

            // set cookie with token and expiration
            setcookie($this->config['cookie_name'], $token, $tokenExpire, '/', '', true, true);

            // set cookie for current request
            $_COOKIE[$this->config['cookie_name']] = $token;
        }
    }

    /**
     * Logs out the current user by deleting the session and user properties.
     *
     * @return void
     */
    public function logout(): void
    {
        if ($this->hasDriver()) {
            $this->getDriver()->logout();
            return; // If a driver is set, use it to handle logout
        }

        // Erase the cache for the logged in user.
        $this->clearCache();

        // Delete the session variable and unset the user property.
        $this->session->delete($this->config['session_key']);
        $this->user = null;
        $this->id = 0;

        // destroy cookie auth if enabled
        if (isset($this->config['cookie_name'])) {
            setcookie($this->config['cookie_name'], '', time() - 3600, '/', '', true, true);
            unset($_COOKIE[$this->config['cookie_name']]);
        }
    }

    /**
     * Clears the cache for the currently logged in user.
     *
     * This method erases the cached user instance if caching is enabled,
     * using the configured cache name and session key to identify the user.
     *
     * @return void
     */
    public function clearCache(): void
    {
        if ($this->config['cache_enabled']) {
            cache($this->config['cache_name'])
                ->erase($this->getId())
                ->unload();
        }
    }

    /**
     * Refreshes the user instance if the user is not a guest.
     *
     * If the user is not a guest, this method will refresh the user instance by
     * deleting the user property and calling the user method again.
     *
     * @return void
     */
    public function refresh(): void
    {
        if ($this->isGuest()) {
            return;
        }

        $this->clearCache();
        unset($this->user);
        $this->getUser();
    }

    /**
     * Magic getter for the admin user properties.
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->getUser()->{$name};
    }

    /**
     * Magic setter for the admin user properties.
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, $value)
    {
        $this->getUser()->{$name} = $value;
    }

    /**
     * Magic isset for the admin user properties.
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name)
    {
        return isset($this->getUser()->{$name});
    }

    /**
     * Magic unset for the admin user properties.
     *
     * @param string $name
     * @return void
     */
    public function __unset(string $name)
    {
        unset($this->getUser()->{$name});
    }

    /**
     * Check if the given field exists in the user model.
     *
     * This method is used to determine if a specific property or field
     * exists in the user model, allowing for dynamic access to user attributes.
     *
     * @param string $key The name of the field to check.
     * @return bool True if the field exists, false otherwise.
     */
    public function offsetExists($key): bool
    {
        return isset($this->{$key});
    }

    /**
     * Method to unset a key-value pair in the user model.
     *
     * @param string $key The key to unset.
     * @return void
     */
    public function offsetUnset($key): void
    {
        unset($this->{$key});
    }

    /**
     *  Method to get a value by key from the user model.
     *
     * This method allows for accessing user properties dynamically using array-like syntax.
     *
     * @param string $key The key to retrieve the value for.
     * @return mixed The value associated with the key, or null if the key does not exist.
     */
    public function offsetGet($key): mixed
    {
        return $this->{$key};
    }

    /**
     *  Method to set a value by key in the user model.
     *  This method allows for dynamically setting user properties using array-like syntax.
     *
     * @param string $key The key to set the value for.
     * @param mixed $value The value to set for the key.
     * @return void
     */
    public function offsetSet($key, $value): void
    {
        $this->{$key} = $value;
    }

    /**
     * Magic method call for the admin user methods.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $args);
        }

        return $this->getUser()->{$method}(...$args);
    }

    /**
     * Checks if the user is authenticated via a cookie.
     *
     * If the configured cookie name is set, this method will attempt to
     * decrypt the cookie value and verify that it contains a valid user ID
     * and expiration time. If the cookie is valid, the user ID will be set
     * in the session and true will be returned.
     *
     * @return bool True if the user is authenticated via a cookie, false otherwise.
     */
    private function checkCookieAuth(): void
    {
        // Check if cookie authentication is enabled
        if ($this->config['cookie_enabled']) {
            $token = $_COOKIE[$this->config['cookie_name']] ?? null;
            if (isset($token)) {
                try {
                    // Attempt to decrypt the cookie value
                    $token = json_decode(decrypt($token), true);

                    // Verify that the decrypted value is an array with an 'id' and 'expire' key
                    if (is_array($token) && isset($token['expire'], $token['id']) && carbon($token['expire'])->isFuture()) {
                        // Set the user ID in the session and return true
                        $this->session->set($this->config['session_key'], $token['id']);
                    }
                } catch (Throwable $e) {
                    // Ignore encryption errors
                }
            }
        }
    }

    /**
     * Checks and sets the authentication ID from the session.
     *
     * This method checks if the session contains the configured session key
     * for the user ID. If it does, it attempts to authenticate via cookie
     * if cookie authentication is enabled. Finally, it sets the internal ID
     * property to the user ID from the session or 0 if not found.
     *
     * @return void
     */
    private function checkAuthId(): void
    {
        if (!$this->session->has($this->config['session_key'])) {
            $this->checkCookieAuth();
        }

        $this->id = intval($this->session->get($this->config['session_key'], 0));
    }

    /**
     * Checks if the Auth instance has a valid driver set.
     *
     * This method checks if the 'driver' configuration is set and if it is an instance
     * of AuthDriverContract, indicating that a valid authentication driver is configured.
     *
     * @return bool True if a valid driver is set, false otherwise.
     */
    private function hasDriver(): bool
    {
        return isset($this->config['driver']) && $this->config['driver'] instanceof AuthDriverContract;
    }

    /**
     * Retrieves the authentication driver instance.
     *
     * This method returns the currently configured authentication driver.
     * If no valid driver is set, it throws a RuntimeException.
     *
     * @return AuthDriverContract The authentication driver instance.
     * @throws \RuntimeException If no valid authentication driver is set.
     */
    private function getDriver(): AuthDriverContract
    {
        if (!$this->hasDriver()) {
            throw new \RuntimeException('No valid authentication driver is set.');
        }

        return $this->config['driver'];
    }
}
