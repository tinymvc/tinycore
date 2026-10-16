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
     * Check if a plain text string matches a given hash (alias for validate).
     *
     * @param string $plain
     * @param string $hash
     * @param string $algo
     * @return bool
     */
    public function check(string $plain, string $hash, string $algo = 'sha256'): bool;

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
     * Determine if a given password hash needs to be rehashed.
     *
     * @param string $hashedPassword
     * @return bool
     */
    public function needsRehash(string $hashedPassword): bool;

    /**
     * Check if a string appears to be a hashed password.
     *
     * @param string $value
     * @return bool
     */
    public function isHashed(string $value): bool;

    /**
     * Generate a cryptographically secure pseudo-random string.
     *
     * @param int $length
     * @return string
     */
    public function random(int $length = 32): string;

    /**
     * Generate raw cryptographically secure pseudo-random bytes.
     *
     * @param int $length
     * @return string
     */
    public function randomBytes(int $length = 32): string;

    /**
     * Encrypt a string value.
     *
     * @param string $value The string value to encrypt.
     * @return string The encrypted string.
     */
    public function encrypt(string $value): string;

    /**
     * Encrypt a string value.
     *
     * @param string $value
     * @return string
     */
    public function encryptString(string $value): string;

    /**
     * Decrypt an encrypted string.
     *
     * @param string $encrypted The encrypted string to decrypt.
     * @return string The decrypted string.
     */
    public function decrypt(string $encrypted): string;

    /**
     * Decrypt an encrypted string.
     *
     * @param string $encrypted
     * @return string
     */
    public function decryptString(string $encrypted): string;

    /**
     * Encrypt an array into an encrypted string payload.
     *
     * @param array $data
     * @return string
     */
    public function encryptArray(array $data): string;

    /**
     * Decrypt an encrypted payload back into an array.
     *
     * @param string $encrypted
     * @return array
     */
    public function decryptArray(string $encrypted): array;
}