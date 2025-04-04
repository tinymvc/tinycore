<?php

namespace Spark\Http;

use Spark\Contracts\Http\SessionContract;

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
    /**
     * Constructor for the session class.
     *
     * Initializes the session if it hasn't been started yet.
     *
     * @return void
     */
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Retrieves a value from the session by key.
     *
     * @param string $key The session variable key to retrieve.
     * @param mixed $default Optional default value to return if the key does not exist.
     * @return mixed The session value if it exists, or the default value.
     */
    public function get(string $key, $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Sets a session variable with a specified key and value.
     *
     * @param string $key The session variable key to set.
     * @param mixed $value The value to store in the session.
     * @return void
     */
    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Checks if a session variable exists and is not empty.
     *
     * @param string $key The session variable key to check.
     * @return bool True if the session variable exists and is not empty, false otherwise.
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]) && !empty($_SESSION[$key]);
    }

    /**
     * Deletes a session variable by key.
     *
     * @param string $key The session variable key to delete.
     * @return void
     */
    public function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Regenerates the session ID to prevent session fixation attacks.
     * Optionally deletes the old session file.
     *
     * @return void
     */
    public function regenerate(bool $deleteOldSession = false): bool
    {
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Destroys the current session and deletes all session data.
     *
     * @return void
     */
    public function destroy(): bool
    {
        return session_destroy();
    }

    /**
     * Returns the current session ID.
     *
     * @return string The current session ID.
     */
    public function id(): string
    {
        return session_id();
    }

    /**
     * Sets a flash message that will be available for one request only.
     *
     * @param string $key The flash message key.
     * @param mixed $value The value to store.
     * @return void
     */
    public function flash(string $key, $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Retrieves a flash message by key and removes it from the session.
     *
     * @param string $key The flash message key.
     * @param mixed $default Default value if key does not exist.
     * @return mixed The flash message value or default if not found.
     */
    public function getFlash(string $key, $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * Checks if a flash message exists.
     *
     * @param string $key The flash message key.
     * @return bool True if the flash message exists, false otherwise.
     */
    public function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }

    /**
     * Clears all flash messages.
     *
     * @return void
     */
    public function clearFlash(): void
    {
        unset($_SESSION['_flash']);
    }
}
