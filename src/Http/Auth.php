<?php

namespace Spark\Http;

use Spark\Contracts\Http\AuthContract;
use Spark\Database\Model;
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
class Auth implements AuthContract
{
    /**
     * @var false|Model The currently logged in user.
     */
    private false|Model $user;

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
        $this->config = array_merge([
            'session_key' => 'admin_user_id',
            'cache_enabled' => true,
            'cache_name' => 'logged_user',
            'cache_expire' => '10 minutes',
            'guest_route' => 'admin.auth.login',
            'logged_in_route' => 'admin.dashboard',
            'cookie_name' => null,
            'cookie_expire' => '30 days',
        ], $config);
    }

    /**
     * Gets the currently logged in admin user.
     *
     * If the user is already cached, it will be returned from cache. 
     * Otherwise, it will be fetched from the database and stored in cache 
     * for the specified cache expiry duration.
     *
     * @return false|Model The currently logged in admin user, or false if not found.
     */
    public function getUser(): false|Model
    {
        // Check if the user's ID is not set and the session has the session key
        if (!isset($this->user)) {
            if ($this->session->has($this->config['session_key']) || $this->hasCookieAuth()) {
                // Attempt to load user from cache if caching is enabled
                if ($this->config['cache_enabled']) {
                    $this->user = cache($this->config['cache_name'])
                        ->load(
                            key: $this->session->get($this->config['session_key']),
                            callback: fn() => $this->userModel::find(
                                $this->session->get($this->config['session_key'])
                            ),
                            expire: $this->config['cache_expire']
                        );
                    unload_cache($this->config['cache_name']); // Unload cache after use
                } else {
                    // Fetch user directly from the database if caching is not enabled
                    $this->user = $this->userModel::find(
                        $this->session->get($this->config['session_key'])
                    );
                }
            } else {
                $this->user = false;
            }
        }

        // Return the currently logged in user
        return $this->user;
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
    protected function hasCookieAuth(): bool
    {
        // Check if a cookie name is configured
        if (isset($this->config['cookie_name'])) {
            $token = $_COOKIE[$this->config['cookie_name']] ?? null;
            if (isset($token) && !empty($token) && is_string($token)) {
                try {
                    // Attempt to decrypt the cookie value
                    $token = json_decode(
                        get(Hash::class)
                            ->decrypt($token),
                        true
                    );

                    // Verify that the decrypted value is an array with an 'id' and 'expire' key
                    if (is_array($token) && isset($token['expire']) && isset($token['id']) && time() < $token['expire']) {
                        // Set the user ID in the session and return true
                        $this->session->set($this->config['session_key'], $token['id']);
                        return true;
                    }
                } catch (Throwable $e) {
                    // If an error occurs, rethrow it if debug mode is enabled
                    if (config('debug')) {
                        throw $e;
                    }
                }
            }
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
        return $this->getUser() === false;
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
        $this->session->set($this->config['session_key'], $user->id);
        $this->user = $user;

        if ($remember && isset($this->config['cookie_name'])) {
            // set cookie expiration time
            $tokenExpire = strtotime($this->config['cookie_expire'] ?? '30 days');

            // add user hashed token in cookie with expiration
            $token = get(Hash::class)
                ->encrypt(
                    json_encode(['id' => $user->id, 'expire' => $tokenExpire])
                );

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
        // Erase the cache for the logged in user.
        $this->clearCache();

        // Delete the session variable and unset the user property.
        $this->session->delete($this->config['session_key']);
        unset($this->user);

        // destroy cookue auth if enabled
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
                ->erase($this->session->get($this->config['session_key']))
                ->unload();
        }
    }

    /**
     * Refreshes the user instance if the user is not a guest.
     *
     * If the user is not a guest, this method will refresh the user instance by
     * deleting the user property and calling the getUser method again.
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
     * Magic method call for the admin user methods.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        return $this->getUser()->{$method}(...$args);
    }
}
