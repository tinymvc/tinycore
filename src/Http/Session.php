<?php

namespace Spark\Http;

use Spark\Contracts\Http\SessionContract;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Class Session
 * 
 * Manages session data for the application, providing methods to store,
 * retrieve, check, and delete session variables, as well as regenerate and
 * destroy the session.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Session implements SessionContract
{
    use Macroable, Conditionable;

    /**
     * Constructor for the session class.
     *
     * Initializes the session if it hasn't been started yet.
     *
     * @return void
     */
    public function __construct()
    {
        self::start();
    }

    /**
     * Starts the session if it hasn't been started yet.
     *
     * @return void
     */
    public static function start(): void
    {
        if (!is_web() || session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }

    /**
     * Checks if the session has been started.
     *
     * @return bool True if the session is active, false otherwise.
     */
    public static function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Retrieves a value from the session by key.
     *
     * @param string $key The session variable key to retrieve.
     * @param mixed $default Optional default value to return if the key does not exist.
     * @return mixed The session value if it exists, or the default value.
     */
    public static function get(string $key, $default = null): mixed
    {
        if (!self::isStarted()) {
            return $default;
        }

        return $_SESSION[$key] ?? $default;
    }

    /**
     * Sets a session variable with a specified key and value.
     *
     * @param string $key The session variable key to set.
     * @param mixed $value The value to store in the session.
     * @return void
     */
    public static function set(string $key, $value): void
    {
        if (!self::isStarted()) {
            return;
        }

        self::put($key, $value);
    }

    /**
     * Checks if a session variable exists and is not empty.
     *
     * @param string $key The session variable key to check.
     * @return bool True if the session variable exists and is not empty, false otherwise.
     */
    public static function has(string $key): bool
    {
        if (!self::isStarted()) {
            return false;
        }

        return array_key_exists($key, $_SESSION) && $_SESSION[$key] !== null;
    }

    /**
     * Deletes a session variable by key.
     *
     * @param string $key The session variable key to delete.
     * @return void
     */
    public static function delete(string $key): void
    {
        if (!self::isStarted()) {
            return;
        }

        self::forget($key);
    }

    /**
     * Adds one or more values to the session.
     *
     * @param string|array<string, mixed> $key The session key or an associative array of session values.
     * @param mixed $value The value to store when using a single key.
     * @return void
     */
    public static function put(string|array $key, $value = null): void
    {
        if (!self::isStarted()) {
            return;
        }

        if (is_array($key)) {
            foreach ($key as $sessionKey => $sessionValue) {
                if (!is_string($sessionKey)) {
                    continue;
                }
                $_SESSION[$sessionKey] = $sessionValue;
            }

            return;
        }

        $_SESSION[$key] = $value;
    }

    /**
     * Removes one or more values from the session.
     *
     * @param string|array<string> $keys The session key or keys to remove.
     * @return void
     */
    public static function forget(string|array $keys): void
    {
        if (!self::isStarted()) {
            return;
        }

        foreach ((array) $keys as $sessionKey) {
            if (is_string($sessionKey)) {
                unset($_SESSION[$sessionKey]);
            }
        }
    }

    /**
     * Removes all session data.
     *
     * @return void
     */
    public static function flush(): void
    {
        if (!self::isStarted()) {
            return;
        }

        $_SESSION = [];
    }

    /**
     * Gets and forgets an item from the session.
     *
     * @param string $key The session key.
     * @param mixed $default Default value if the key does not exist.
     * @return mixed The session value or default.
     */
    public static function pull(string $key, $default = null): mixed
    {
        if (!self::isStarted()) {
            return $default;
        }

        if (!array_key_exists($key, $_SESSION)) {
            return $default;
        }

        $value = $_SESSION[$key];
        unset($_SESSION[$key]);

        return $value;
    }

    /**
     * Clears the session and regenerates the session identifier.
     *
     * @param bool $deleteOldSession Whether to delete the old session file.
     * @return bool Whether the session was invalidated.
     */
    public static function invalidate(bool $deleteOldSession = true): bool
    {
        if (!is_web() || !self::isStarted()) {
            return false;
        }

        self::flush();
        session_unset();

        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Regenerates the session ID to prevent session fixation attacks.
     * Optionally deletes the old session file.
     *
     * @return void
     */
    public static function regenerate(bool $deleteOldSession = false): bool
    {
        if (!is_web() || !self::isStarted()) {
            return false;
        }

        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Destroys the current session and deletes all session data.
     *
     * @return void
     */
    public static function destroy(): void
    {
        if (!is_web() || !self::isStarted()) {
            return;
        }

        $_SESSION = [];
        session_unset();
        session_destroy();

        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
    }

    /**
     * Returns the current session ID.
     *
     * @return string The current session ID.
     */
    public static function id(): string
    {
        if (!self::isStarted()) {
            return '';
        }

        return session_id();
    }

    /**
     * Sets a flash message that will be available for one request only.
     *
     * @param string $key The flash message key.
     * @param mixed $value The value to store.
     * @return void
     */
    public static function flash(string $key, $value): void
    {
        if (!self::isStarted()) {
            return;
        }

        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Retrieves a flash message by key and removes it from the session.
     *
     * @param string $key The flash message key.
     * @param mixed $default Default value if key does not exist.
     * @return mixed The flash message value or default if not found.
     */
    public static function getFlash(string $key, $default = null): mixed
    {
        if (!self::isStarted()) {
            return $default;
        }

        if (!isset($_SESSION['_flash']) || !array_key_exists($key, $_SESSION['_flash'])) {
            return $default;
        }

        $value = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        if (empty($_SESSION['_flash'])) {
            unset($_SESSION['_flash']);
        }

        return $value;
    }

    /**
     * Checks if a flash message exists.
     *
     * @param string $key The flash message key.
     * @return bool True if the flash message exists, false otherwise.
     */
    public static function hasFlash(string $key): bool
    {
        if (!self::isStarted() || !isset($_SESSION['_flash'])) {
            return false;
        }

        return array_key_exists($key, $_SESSION['_flash']);
    }

    /**
     * Clears all flash messages.
     *
     * @return void
     */
    public static function clearFlash(): void
    {
        if (!self::isStarted()) {
            return;
        }

        unset($_SESSION['_flash']);
    }

    /**
     * Checks if the session is started.
     *
     * @return array<string, mixed> The session data if the session is started, an empty array otherwise.
     */
    public static function all(): array
    {
        if (!self::isStarted()) {
            return [];
        }

        return $_SESSION;
    }

    /**
     * Close session and write data to the session storage.
     *
     * @return void
     */
    public static function close(): void
    {
        self::isStarted() && session_write_close();
    }
}
