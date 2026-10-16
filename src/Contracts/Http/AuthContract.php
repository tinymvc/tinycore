<?php

namespace Spark\Contracts\Http;

use Spark\Contracts\Support\Arrayable;
use Spark\Database\Model;

/**
 * Interface for the Auth Util class.
 *
 * This class provides the ability to log users in, log users out, and check if a user is logged in.
 */
interface AuthContract
{
    /**
     * Checks if a user is currently authenticated.
     *
     * @return bool
     */
    public function check(): bool;

    /**
     * Checks if a user is currently logged in (alias for check).
     *
     * @return bool
     */
    public function isLogged(): bool;

    /**
     * Get the authenticated user model or a specific attribute from the user.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function user(?string $key = null, $default = null): mixed;

    /**
     * Get the ID of the currently authenticated user.
     *
     * @return int
     */
    public function id(): int;

    /**
     * Attempt to authenticate a user using credentials.
     *
     * @param Arrayable|array $credentials
     * @param bool $remember
     * @return bool
     */
    public function attempt(Arrayable|array $credentials, bool $remember = false): bool;

    /**
     * Gets the currently logged in user.
     *
     * If the user is already cached, it will be returned from cache. 
     * Otherwise, it will be fetched from the database and stored in cache 
     * for the specified cache expiry duration.
     *
     * @return ?Model The currently logged in user, or null if not found.
     */
    public function getUser(): ?Model;

    /**
     * Checks if the current user is a guest (not logged in).
     *
     * @return bool True if the user is a guest, false otherwise.
     */
    public function isGuest(): bool;

    /**
     * Logs in the specified user by setting the session and user properties.
     *
     * @param Model $user The user model to be logged in.
     * @param bool $remember Optional parameter to determine if the user should be remembered.
     * @return void
     */
    public function login(Model $user, bool $remember = false): void;

    /**
     * Set the current authenticated user model.
     *
     * @param Model $user
     * @return self
     */
    public function setUser(Model $user): self;

    /**
     * Log a user into the application by their ID.
     *
     * @param int|string $id
     * @param bool $remember
     * @return Model|null
     */
    public function loginUsingId(int|string $id, bool $remember = false): ?Model;

    /**
     * Logs out the current user by deleting the session and user properties.
     *
     * @return void
     */
    public function logout(): void;

    /**
     * Refreshes the user instance if the user is not a guest.
     *
     * If the user is not a guest, this method will refresh the user instance by
     * deleting the user property and calling the getUser method again.
     *
     * @return void
     */
    public function refresh(): void;
}