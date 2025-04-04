<?php

namespace Spark\Contracts;

interface HashContract
{
    /**
     * Create a unique hash using the provided algorithm.
     *
     * @param string $plain The plaintext string to hash.
     * @param string $algo The hashing algorithm to use (default: sha256).
     * @return string The resulting hash.
     */
    public function make(string $plain, string $algo = 'sha256'): string;

    /**
     * Validate a hash against a plain text value.
     *
     * @param string $plain The plaintext string.
     * @param string $hash The hash to validate.
     * @param string $algo The algorithm used to create the hash (default: sha256).
     * @return bool True if the hash matches, false otherwise.
     */
    public function validate(string $plain, string $hash, string $algo = 'sha256'): bool;

    /**
     * Hash a password securely.
     *
     * @param string $password The plain text password to hash.
     * @return string The hashed password.
     */
    public function hashPassword(string $password): string;

    /**
     * Validate a plain text password against a hashed password.
     *
     * @param string $password The plain text password.
     * @param string $hashedPassword The hashed password to validate against.
     * @return bool True if the password matches, false otherwise.
     */
    public function validatePassword(string $password, string $hashedPassword): bool;

    /**
     * Encrypt a string value.
     *
     * @param string $value The string value to encrypt.
     * @return string The encrypted string.
     */
    public function encrypt(string $value): string;

    /**
     * Decrypt an encrypted string.
     *
     * @param string $encrypted The encrypted string to decrypt.
     * @return string The decrypted string.
     */
    public function decrypt(string $encrypted): string;
}