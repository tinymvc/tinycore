<?php

namespace Spark\Contracts\Http;

use Spark\Database\Model;

/**
 * Interface for the Auth Util class.
 *
 * This class provides the ability to log users in, log users out, and check if a user is logged in.
 */
interface AuthContract
{
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