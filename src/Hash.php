<?php

namespace Spark;

use Spark\Contracts\HashContract;
use Spark\Exceptions\Hash\DecryptionFailedException;
use Spark\Exceptions\Hash\EncryptionFailedException;
use Spark\Exceptions\Hash\InvalidEncryptingKeyException;
use Spark\Support\Traits\Macroable;

/**
 * Class Hash
 *
 * Provides methods for creating and validating hashed values, 
 * and encrypting/decrypting strings using secure algorithms.
 *
 * @package Spark\Utils
 */
class Hash implements HashContract
{
    use Macroable;

    /**
     * The algorithm used for password hashing.
     *
     * This is the name of an algorithm supported by the password_hash() function.
     *
     * @var string
     */
    private string $passwordAlgorithm;

    /**
     * Default options for password hashing.
     *
     * These options are used for configuring the memory cost, time cost,
     * and number of threads in password hashing algorithms.
     *
     * @var array
     */
    private array $passwordOptions;

    /**
     * Initializes the hash class with a key.
     * 
     * The key will be used for cryptographic operations.
     * 
     * If the key is not provided, the value of the app_key environment variable
     * will be used if it is set. Otherwise, a default key will be used.
     * 
     * The provided key must be at least 32 characters long. If the key is less than 32 characters,
     * an exception will be thrown.
     * 
     * @param string|null $key The encryption key to use.
     */
    public function __construct(private ?string $key = null)
    {
        $key ??= config('app_key'); // Get the key from the environment if not provided, otherwise use the default key
        if ($key === null) {
            throw new InvalidEncryptingKeyException('Encryption key not provided.');
        }

        $this->setKey($key); // Set the encryption key

        // Default password algorithm
        $this->passwordAlgorithm = PASSWORD_ARGON2ID;

        // Set default password options
        $this->passwordOptions = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2];
    }

    /**
     * Set the encryption key to use for cryptographic operations.
     *
     * The provided key must be at least 32 characters long. If the key is less than 32 characters,
     * an exception will be thrown.
     *
     * @param string $key The encryption key to use.
     * @throws InvalidEncryptingKeyException If the provided key is less than 32 characters.
     */
    public function setKey(string $key): void
    {
        // Check if the key is less than 32 characters
        if (strlen($key) < 32) {
            throw new InvalidEncryptingKeyException('The provided key must be at least 32 characters long.');
        }

        // Hash the key using SHA-256
        $this->key = hash('sha256', $key);
    }

    /**
     * Set the algorithm to use for password hashing.
     * 
     * The algorithm should be one of the supported algorithms by the password_hash function.
     * 
     * @param string $algorithm The algorithm to use for password hashing.
     * 
     * @see https://www.php.net/manual/en/password.constants.php
     */
    public function setPasswordAlgorithm(string $algorithm): void
    {
        $this->passwordAlgorithm = $algorithm;
    }

    /**
     * Set the default options to use for password hashing.
     *
     * The options should be an associative array with the following keys:
     * - memory_cost: The amount of memory to use in bytes (default: 65536).
     * - time_cost: The amount of time to use in seconds (default: 4).
     * - threads: The number of threads to use (default: 2).
     *
     * @param array $options The options to use for password hashing.
     */
    public function setPasswordOptions(array $options): void
    {
        $this->passwordOptions = array_merge($this->passwordOptions, $options);
    }

    /**
     * Create a unique hash using the provided algorithm.
     *
     * @param string $plain The plaintext string to hash.
     * @param string $algo The hashing algorithm to use (default: sha256).
     * @return string The resulting hash.
     */
    public function make(string $plain, string $algo = 'sha256'): string
    {
        return hash_hmac($algo, $plain, $this->key);
    }

    /**
     * Validate a hash against a plain text value.
     *
     * @param string $plain The plaintext string.
     * @param string $hash The hash to validate.
     * @param string $algo The algorithm used to create the hash (default: sha256).
     * @return bool True if the hash matches, false otherwise.
     */
    public function validate(string $plain, string $hash, string $algo = 'sha256'): bool
    {
        return hash_equals($hash, $this->make($plain, $algo));
    }

    /**
     * Hash a password securely using Argon2id or Bcrypt.
     *
     * @param string $password The plain text password to hash.
     * @return string The hashed password.
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, $this->passwordAlgorithm, $this->passwordOptions);
    }

    /**
     * Validate a plain text password against a hashed password.
     *
     * @param string $password The plain text password to validate.
     * @param string $hashedPassword The hashed password to validate against.
     * @return bool True if the password matches, false otherwise.
     */
    public function validatePassword(string $password, string $hashedPassword): bool
    {
        return password_verify($password, $hashedPassword);
    }

    /**
     * Create a hashed password.
     *
     * @param string $password The plain text password to hash.
     * @param string|null $hash Optional existing hash (not used in this implementation).
     * @return bool|string The hashed password or validation result.
     */
    public function password(string $password, ?string $hash = null): bool|string
    {
        if (!empty($hash)) {
            return $this->validatePassword($password, $hash);
        }

        return $this->hashPassword($password);
    }

    /**
     * Verify a plain text password against a hashed password.
     *
     * @param string $password The plain text password to verify.
     * @param string $hashedPassword The hashed password to verify against.
     * @return bool True if the password matches, false otherwise.
     */
    public function verify(string $password, string $hashedPassword): bool
    {
        return $this->validatePassword($password, $hashedPassword);
    }

    /**
     * Encrypts a string using AES-256-CBC symmetric encryption.
     *
     * @param string $value The plaintext string to encrypt.
     * @return string The encrypted string, base64 encoded.
     * @throws EncryptionFailedException
     */
    public function encrypt(string $value): string
    {
        // Generate a cryptographically secure random IV
        $iv = random_bytes(openssl_cipher_iv_length('AES-256-CBC'));

        // Encrypt the value
        $cipherText = openssl_encrypt($value, 'AES-256-CBC', $this->key, 0, $iv);

        // Check if encryption was successful
        if ($cipherText === false) {
            throw new EncryptionFailedException('Encryption failed.');
        }

        // Use JSON encoding for cleaner and safer storage of IV and ciphertext
        $encryptedData = json_encode([
            'cipherText' => $cipherText,
            'iv' => base64_encode($iv)
        ]);

        if ($encryptedData === false) {
            throw new EncryptionFailedException('Failed to encode encrypted data.');
        }

        return base64_encode($encryptedData);
    }

    /**
     * Decrypts a string encrypted with the encrypt method.
     *
     * @param string $encrypted The base64-encoded encrypted string.
     * @return string The decrypted plaintext string.
     * @throws DecryptionFailedException
     */
    public function decrypt(string $encrypted): string
    {
        $decodedData = base64_decode($encrypted, true);

        if ($decodedData === false) {
            throw new DecryptionFailedException('Invalid base64-encoded data.');
        }

        $data = json_decode($decodedData, true);

        if (!is_array($data) || empty($data['cipherText']) || empty($data['iv'])) {
            throw new DecryptionFailedException('Invalid encrypted data format.');
        }

        $iv = base64_decode($data['iv'], true);

        if ($iv === false) {
            throw new DecryptionFailedException('Invalid IV format.');
        }

        $plainText = openssl_decrypt($data['cipherText'], 'AES-256-CBC', $this->key, 0, $iv);

        if ($plainText === false) {
            throw new DecryptionFailedException('Decryption failed.');
        }

        return $plainText;
    }

    /**
     * Generate a cryptographically secure random string.
     *
     * @param int $length The number of bytes to generate.
     * @return string The generated random string.
     */
    public function random(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}
