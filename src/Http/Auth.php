<?php

namespace Spark\Http;

use ArrayAccess;
use Spark\Contracts\Http\AuthContract;
use Spark\Contracts\Http\AuthDriverContract;
use Spark\Database\Model;
use Spark\Support\Traits\Macroable;
use Throwable;
use function intval;
use function is_array;

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
    use Macroable;

    /** @var Session The session instance used for managing user sessions. */
    protected Session $session;

    /**
     * @var ?Model The currently logged in user.
     */
    protected ?Model $user;

    /** @var string The fully qualified class name of the user model. */
    protected string $model;

    /** @var array Configuration settings for the Auth instance. */
    protected array $config;

    /** @var int The ID of the currently logged in user. */
    protected int $id;

    /**
     * Constructor for the Auth class.
     *
     * Initializes the Auth instance with the specified session management, user model,
     * and optional configuration settings.
     *
     * @param Session $session The session instance used for managing user sessions.
     * @param string $model The fully qualified class name of the user model.
     * @param array $config Optional configuration array for customizing session key,
     *                      cache settings, and route redirections.
     */
    public function __construct(null|string $model = null, array $config = [])
    {
        /** @var Session $session */
        $this->session = \Spark\Foundation\Application::$app->make(Session::class);

        // Set the user model, defaulting to \App\Models\User if none is provided
        $this->model = $model ?? \App\Models\User::class;

        $this->config = [
            'session_key' => 'user_id',
            'cache_enabled' => false,
            'cache_name' => 'auth_cache',
            'cache_expire' => '10 minutes',
            'login_route' => 'login',
            'redirect_route' => 'dashboard',
            'cookie_enabled' => true,
            'cookie_name' => 'auth',
            'cookie_expire' => '6 months',
            'jwt_enabled' => false,
            'jwt_expire' => '6 months',
            'use_remember_token' => false,
            'driver' => null,
            ...$config
        ];

        $this->check(); // Check and set the authentication ID from the session
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
        $this->config = [...$this->config, ...$config];
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
                        callback: fn() => $this->model::find($this->getId()) ?: null,
                        expire: $this->config['cache_expire']
                    );
                unload_cache($this->config['cache_name']); // Unload cache after use
            } else {
                // Fetch user directly from the database if caching is not enabled
                $user = $this->model::find($this->getId()) ?: null;
            }

            if (isset($user, $user->id)) {
                $this->user = $user; // Set the user property if a valid user is found
            } else {
                $this->logout(); // Logout if the user is not found in the database
            }
        }

        // Return the currently logged in user
        return $this->user ??= null;
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
     * @return \App\Models\User|mixed The user model or false if not logged in, or the value of the specified key.
     */
    public function user(?string $key = null, $default = null): mixed
    {
        if ($key !== null && !$this->isGuest()) {
            return $this->getUser()->get($key, $default);
        }

        return $this->getUser(); // Return the user model or false if not logged in
    }

    /**
     * Retrieves the ID of the currently logged in user.
     *
     * @return int The user ID or 0 if not logged in.
     */
    public function id(): int
    {
        return $this->getId();
    }

    /**
     * Attempts to authenticate a user with the given credentials.
     *
     * @param array $credentials An array containing the user's credentials (e.g., email and password).
     * @return bool True if authentication is successful, false otherwise.
     */
    public function attempt(array $credentials): bool
    {
        $identifier = \Spark\Support\Arr::except($credentials, ['password']);

        $user = $this->model::where($identifier)->first();
        if ($user && passcode($credentials['password'], $user->password)) {
            $this->login($user);
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
    public function getLoginRoute(): string
    {
        return route_url($this->config['login_route']);
    }

    /**
     * Retrieves the route for logged in users.
     *
     * This route is used to redirect users who are logged in
     * to the appropriate admin dashboard or login success endpoint.
     *
     * @return string The route path for logged in users.
     */
    public function getRedirectRoute(): string
    {
        if ($this->session->hasFlash('__auth_redirect')) {
            return $this->session->getFlash('__auth_redirect');
        }

        return route_url($this->config['redirect_route']);
    }

    /**
     * Checks if the current user is a guest (not logged in).
     *
     * @return bool True if the user is a guest, false otherwise.
     */
    public function isGuest(): bool
    {
        return !$this->isLogged();
    }

    /**
     * Checks if the current user is logged in.
     *
     * @return bool True if the user is logged in, false otherwise.
     */
    public function isLogged(): bool
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

        if ($remember && $this->config['cookie_enabled']) {
            // set cookie expiration time
            $tokenExpire = strtotime($this->config['cookie_expire'] ?? '1 year');

            // add user hashed token in cookie with expiration
            if ($this->config['use_remember_token']) {
                // generate a random token and store it in the database
                $rememberToken = hasher()->random(16);
                $token = encrypt($rememberToken);

                // update the user's remember token in the database
                $this->user->set('remember_token', $rememberToken);
                $this->user->save(); // Save the updated user model
            } else {
                // create an encrypted token with user id and expiration
                $token = encrypt(['id' => $user->id, 'expire' => $tokenExpire]);
            }

            // set cookie with token and expiration
            cookie($this->config['cookie_name'], $token, [
                'expires' => $tokenExpire,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
            ]);
        }
    }

    /**
     * Generates a JWT token for the specified user with an optional payload.
     *
     * This method creates a JWT token that includes the user's ID and an expiration time.
     * Additional payload data can be included by passing an associative array.
     *
     * @param Model $user The user model for whom the JWT token is to be generated.
     * @param array $payload Optional associative array of additional payload data to include in the token.
     * @return string The generated JWT token as a string.
     * @throws \InvalidArgumentException If the payload does not contain an 'id' key.
     */
    public function getJwtToken(Model $user, array $payload = []): string
    {
        $expire = $this->config['jwt_expire'] ?? '1 year';

        $payload['id'] = $user->id;
        $payload['exp'] = strtotime("+$expire");

        return encrypt($payload);
    }

    /**
     * Generates a JWT token for the currently logged in user with an optional payload.
     *
     * This method creates a JWT token that includes the user's ID and an expiration time.
     * Additional payload data can be included by passing an associative array.
     *
     * @param array $payload Optional associative array of additional payload data to include in the token.
     * @return string The generated JWT token as a string.
     */
    public function createJwtToken(array $payload): string
    {
        $user = $this->getUser();

        if (!isset($user)) {
            throw new \RuntimeException('No authenticated user found to create JWT token.');
        }

        return $this->getJwtToken($user, $payload);
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

        // destroy cookie auth if enabled
        if ($this->config['cookie_enabled']) {
            // Clear the remember token from the database
            if ($this->hasId() && isset($this->user, $this->user['remember_token']) && $this->config['use_remember_token']) {
                $this->user->set('remember_token', null);
                $this->user->save(); // Save the updated user model
            }

            // Delete the authentication cookie by setting its expiration in the past
            cookie($this->config['cookie_name'], '', -3600);
        }

        // Delete the session variable and unset the user property.
        $this->session->delete($this->config['session_key']);
        $this->user = null;
        $this->id = 0;
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
        $this->check(); // Ensure the authentication state is up to date

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
        return $this->offsetGet($name);
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
        $this->offsetSet($name, $value);
    }

    /**
     * Magic isset for the admin user properties.
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name)
    {
        return $this->offsetExists($name);
    }

    /**
     * Magic unset for the admin user properties.
     *
     * @param string $name
     * @return void
     */
    public function __unset(string $name)
    {
        $this->offsetUnset($name);
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
        return isset($this->getUser()->{$key});
    }

    /**
     * Method to unset a key-value pair in the user model.
     *
     * @param string $key The key to unset.
     * @return void
     */
    public function offsetUnset($key): void
    {
        unset($this->getUser()->{$key});
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
        return $this->getUser()->{$key};
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
        $this->getUser()->{$key} = $value;
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
    protected function checkCookieAuth(): void
    {
        $cookieToken = $_COOKIE[$this->config['cookie_name']] ?? null;
        if (!empty($cookieToken)) {
            try {
                // Attempt to decrypt the cookie value
                $token = decrypt($cookieToken);

                if ($this->config['use_remember_token']) {
                    $userId = $this->model::column('id')->where('remember_token', $token)->first();
                    if ($userId) {
                        $this->session->set($this->config['session_key'], $userId);
                    }

                    return; // Exit after processing remember token
                }

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

    /**
     * Checks if the user is authenticated via a JWT token.
     *
     * This method checks for a JWT token in the Authorization header of the request.
     * If a valid token is found, it decrypts the token and verifies that it contains
     * a valid user ID and expiration time. If the token is valid, the user ID will
     * be set in the session.
     *
     * @return ?int The user ID if authenticated via JWT, or null if not authenticated.
     */
    protected function checkJwtAuth(): ?int
    {
        $authHeader = request()->header('authorization');

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];

            try {
                $payload = decrypt($token);
                if (is_array($payload) && isset($payload['id'], $payload['exp']) && carbon($payload['exp'])->isFuture()) {
                    return $payload['id'];
                }
            } catch (Throwable $e) {
                // Ignore decryption errors
            }
        }

        return null;
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
    public function check(): void
    {
        if ($this->hasDriver()) {
            $this->id = $this->getDriver()->checkId();
            return; // If a driver is set, use it to check the ID
        }

        if (!$this->session->has($this->config['session_key']) && $this->config['cookie_enabled']) {
            $this->checkCookieAuth(); // Check cookie authentication if enabled
        }

        if (!$this->session->has($this->config['session_key']) && $this->config['jwt_enabled']) {
            $id = $this->checkJwtAuth(); // Check JWT authentication if enabled
            if ($id && $id > 0) {
                $this->id = intval($id);
                return; // If JWT auth is successful, set the ID and return
            }
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
    protected function hasDriver(): bool
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
    protected function getDriver(): AuthDriverContract
    {
        if (!$this->hasDriver()) {
            throw new \RuntimeException('No valid authentication driver is set.');
        }

        return $this->config['driver'];
    }
}
