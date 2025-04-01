<?php

namespace Spark\Contracts\Utils;

interface HashUtilContract
{
    public function make(string $plain, string $algo = 'sha256'): string;

    public function validate(string $plain, string $hash, string $algo = 'sha256'): bool;

    public function hashPassword(string $password): string;

    public function validatePassword(string $password, string $hashedPassword): bool;

    public function encrypt(string $value): string;

    public function decrypt(string $encrypted): string;
}