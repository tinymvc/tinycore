<?php

namespace Spark\Contracts\Utils;

use Spark\Database\Model;

interface AuthUtilContract
{
    public function getUser(): false|Model;

    public function isGuest(): bool;

    public function login(Model $user, bool $remember = false): void;

    public function logout(): void;

    public function refresh(): void;

}