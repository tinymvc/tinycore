<?php

namespace Spark\Contracts\Http;

use Spark\Database\Model;

/**
 * Interface AuthDriverContract
 *
 * This interface defines the methods required for an authentication driver.
 * It allows for user login, logout, and checking if a user is currently logged in.
 */
interface AuthDriverContract
{
    /**
     * Log a user in.
     *
     * @param Model $user
     * @return void
     */
    public function login(Model $user): void;

    /**
     * Log the current user out.
     *
     * @return void
     */
    public function logout(): void;

    /**
     * Get the currently logged-in user.
     *
     * @return Model|false
     */
    public function getUser(): Model|false;
}