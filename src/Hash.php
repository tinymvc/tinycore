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

        // Derive a proper 32-byte binary key using HKDF (Key Derivation Function)
        $this->key = hash_hkdf('sha256', $key, 32, 'aes-256-encryption');
    }

    /**
     * Set the algorithm to use for password hashing.
     * 
     * The algorithm should be one of the supported algorithms by the password_hash function.
     * 
     * @param string|int $algorithm The algorithm to use for password hashing.
     * @throws \InvalidArgumentException If the algorithm is not supported.
     */
    public function setPasswordAlgorithm(string|int $algorithm): void
    {
        // Validate that the algorithm is supported
        $supportedAlgorithms = [PASSWORD_BCRYPT, PASSWORD_ARGON2I, PASSWORD_ARGON2ID];

        if (!in_array($algorithm, $supportedAlgorithms, true)) {
            throw new \InvalidArgumentException('Unsupported password hashing algorithm.');
        }

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
     * Check if a plain text value matches a given hash.
     *
     * @param string $plain The plaintext string.
     * @param string $hash The hash to check against.
     * @param string $algo The algorithm used to create the hash (default: sha256).
     * @return bool True if the hash matches, false otherwise.
     */
    public function check(string $plain, string $hash, string $algo = 'sha256'): bool
    {
        return $this->validate($plain, $hash, $algo);
    }

    /**
     * Compare two hashes in a timing-attack safe manner.
     *
     * @param string $knownHash The known hash to compare against.
     * @param string $userString The user-provided string to compare.
     * @return bool True if the hashes match, false otherwise.
     */
    public function equals(string $knownHash, string $userString): bool
    {
        return hash_equals($knownHash, $userString);
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
        if ($hash !== null) {
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
     * Check if a hashed password needs to be rehashed according to the current algorithm and options.
     *
     * @param string $hashedPassword The hashed password to check.
     * @return bool True if the password needs to be rehashed, false otherwise.
     */
    public function needsRehash(string $hashedPassword): bool
    {
        return password_needs_rehash($hashedPassword, $this->passwordAlgorithm, $this->passwordOptions);
    }

    /**
     * Get information about a hashed password.
     *
     * @param string $hashedPassword The hashed password to get information about.
     * @return array An associative array containing information about the hashed password.
     */
    public function passwordInfo(string $hashedPassword): array
    {
        return password_get_info($hashedPassword);
    }

    /**
     * Get the current password hashing algorithm.
     *
     * @return string The current password hashing algorithm.
     */
    public function getPasswordAlgorithm(): string
    {
        return $this->passwordAlgorithm;
    }

    /**
     * Get the current password hashing options.
     *
     * @return array The current password hashing options.
     */
    public function getPasswordOptions(): array
    {
        return $this->passwordOptions;
    }

    /**
     * Get the current encryption key.
     *
     * @return string The current encryption key.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Encrypts a string using AES-256-CBC symmetric encryption with HMAC authentication.
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
        $cipherText = openssl_encrypt($value, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $iv);

        // Check if encryption was successful
        if ($cipherText === false) {
            throw new EncryptionFailedException('Encryption failed.');
        }

        // Create HMAC for authentication (prevents tampering)
        $hmac = hash_hmac('sha256', $iv . $cipherText, $this->key, true);

        // Use JSON encoding for cleaner and safer storage of IV, ciphertext, and HMAC
        $encryptedData = json_encode([
            'cipherText' => base64_encode($cipherText),
            'iv' => base64_encode($iv),
            'hmac' => base64_encode($hmac)
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
        $cipherText = base64_decode($data['cipherText'], true);

        if ($iv === false || $cipherText === false) {
            throw new DecryptionFailedException('Invalid IV or ciphertext format.');
        }

        // Verify HMAC if present (for authenticated encryption)
        if (!empty($data['hmac'])) {
            $hmac = base64_decode($data['hmac'], true);

            if ($hmac === false) {
                throw new DecryptionFailedException('Invalid HMAC format.');
            }

            $calculatedHmac = hash_hmac('sha256', $iv . $cipherText, $this->key, true);

            if (!hash_equals($hmac, $calculatedHmac)) {
                throw new DecryptionFailedException('HMAC verification failed. Data may have been tampered with.');
            }
        }

        $plainText = openssl_decrypt($cipherText, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $iv);

        if ($plainText === false) {
            throw new DecryptionFailedException('Decryption failed.');
        }

        return $plainText;
    }

    /**
     * Generate a cryptographically secure random string (hex-encoded).
     *
     * @param int $length The number of bytes to generate (result will be $length * 2 characters).
     * @return string The generated random hex string.
     */
    public function random(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate cryptographically secure random bytes.
     *
     * @param int $length The number of bytes to generate.
     * @return string The generated random bytes.
     */
    public function randomBytes(int $length = 32): string
    {
        return random_bytes($length);
    }

    /**
     * Generate a simple hash (without HMAC) using the specified algorithm.
     * Useful for file checksums, non-cryptographic purposes.
     *
     * @param string $value The value to hash.
     * @param string $algo The hashing algorithm (default: sha256).
     * @return string The hash value.
     */
    public function hash(string $value, string $algo = 'sha256'): string
    {
        return hash($algo, $value);
    }

    /**
     * Generate an HMAC hash.
     *
     * @param string $value The value to hash.
     * @param string $algo The hashing algorithm (default: sha256).
     * @return string The HMAC hash.
     */
    public function hmac(string $value, string $algo = 'sha256'): string
    {
        return hash_hmac($algo, $value, $this->key);
    }

    /**
     * Encrypt an array by serializing it first.
     *
     * @param array $data The array to encrypt.
     * @return string The encrypted string.
     * @throws EncryptionFailedException
     */
    public function encryptArray(array $data): string
    {
        return $this->encrypt(serialize($data));
    }

    /**
     * Decrypt a string and unserialize it to an array.
     *
     * @param string $encrypted The encrypted string.
     * @return array The decrypted array.
     * @throws DecryptionFailedException
     */
    public function decryptArray(string $encrypted): array
    {
        $decrypted = $this->decrypt($encrypted);
        $data = unserialize($decrypted);

        if (!is_array($data)) {
            throw new DecryptionFailedException('Decrypted data is not a valid array.');
        }

        return $data;
    }

    /**
     * Alias for encrypt method.
     *
     * @param string $value The plaintext string to encrypt.
     * @return string The encrypted string.
     * @throws EncryptionFailedException
     */
    public function encryptString(string $value): string
    {
        return $this->encrypt($value);
    }

    /**
     * Alias for decrypt method.
     *
     * @param string $encrypted The encrypted string.
     * @return string The decrypted string.
     * @throws DecryptionFailedException
     */
    public function decryptString(string $encrypted): string
    {
        return $this->decrypt($encrypted);
    }

    /**
     * Timing-attack safe string comparison (alias for equals).
     *
     * @param string $known The known string.
     * @param string $user The user-provided string.
     * @return bool True if equal, false otherwise.
     */
    public function isEqual(string $known, string $user): bool
    {
        return $this->equals($known, $user);
    }

    /**
     * Hash a password using Bcrypt algorithm.
     *
     * @param string $password The password to hash.
     * @param array $options Optional Bcrypt options.
     * @return string The hashed password.
     */
    public function bcrypt(string $password, array $options = []): string
    {
        $options = array_merge(['cost' => 12], $options);
        return password_hash($password, PASSWORD_BCRYPT, $options);
    }

    /**
     * Hash a password using Argon2i algorithm.
     *
     * @param string $password The password to hash.
     * @param array $options Optional Argon2 options.
     * @return string The hashed password.
     */
    public function argon2i(string $password, array $options = []): string
    {
        $options = array_merge($this->passwordOptions, $options);
        return password_hash($password, PASSWORD_ARGON2I, $options);
    }

    /**
     * Hash a password using Argon2id algorithm.
     *
     * @param string $password The password to hash.
     * @param array $options Optional Argon2 options.
     * @return string The hashed password.
     */
    public function argon2id(string $password, array $options = []): string
    {
        $options = array_merge($this->passwordOptions, $options);
        return password_hash($password, PASSWORD_ARGON2ID, $options);
    }

    /**
     * Generate a version 4 UUID.
     *
     * @return string The generated UUID.
     */
    public function uuid(): string
    {
        $data = random_bytes(16);

        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
