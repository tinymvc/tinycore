<?php

namespace Spark\Contracts\Http;

interface SessionContract
{
    /**
     * Retrieves a value from the session by key.
     *
     * @param string $key The session variable key to retrieve.
     * @param mixed $default Optional default value to return if the key does not exist.
     * @return mixed The session value if it exists, or the default value.
     */
    public function get(string $key, $default = null): mixed;

    /**
     * Sets a session variable with a specified key and value.
     *
     * @param string $key The session variable key to set.
     * @param mixed $value The value to store in the session.
     * @return void
     */
    public function set(string $key, $value): void;

    /**
     * Checks if a session variable exists and is not empty.
     *
     * @param string $key The session variable key to check.
     * @return bool True if the session variable exists and is not empty, false otherwise.
     */
    public function has(string $key): bool;

    /**
     * Deletes a session variable by key.
     *
     * @param string $key The session variable key to delete.
     * @return void
     */
    public function delete(string $key): void;

    /**
     * Regenerates the session ID to prevent session fixation attacks.
     * Optionally deletes the old session file.
     *
     * @return bool True on success, false on failure.
     */
    public function regenerate(bool $deleteOldSession = false): bool;

    /**
     * Destroys the current session and deletes all session data.
     *
     * @return bool True on success, false on failure.
     */
    public function destroy(): bool;

    /**
     * Sets a flash message that will be available for one request only.
     *
     * @param string $key The flash message key.
     * @param mixed $value The value to store.
     * @return void
     */
    public function flash(string $key, $value): void;

    /**
     * Retrieves a flash message by key and removes it from the session.
     *
     * @param string $key The flash message key.
     * @param mixed $default Default value if key does not exist.
     * @return mixed The flash message value or default if not found.
     */
    public function getFlash(string $key, $default = null): mixed;
}