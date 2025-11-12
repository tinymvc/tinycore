<?php

namespace Spark\Facades;

use Spark\Hash as BaseHash;

/**
 * Facade Hash
 * 
 * This class serves as a facade for the Hash system, providing a static interface to the underlying Hash class.
 * It allows easy access to hashing methods such as creating and verifying hashes
 * without needing to instantiate the Hash class directly.
 * 
 * @method static void setKey(string $key)
 * @method static void setPasswordAlgorithm(string $algorithm)
 * @method static void setPasswordOptions(array $options)
 * @method static string make(string $plain, string $algo = 'sha256')
 * @method static bool validate(string $plain, string $hash, string $algo = 'sha256')
 * @method static bool check(string $plain, string $hash, string $algo = 'sha256')
 * @method static bool equals(string $knownHash, string $userString)
 * @method static string hashPassword(string $password)
 * @method static bool validatePassword(string $password, string $hashedPassword)
 * @method static bool|string password(string $password, ?string $hash = null)
 * @method static bool verify(string $password, string $hashedPassword)
 * @method static bool needsRehash(string $hashedPassword)
 * @method static array passwordInfo(string $hashedPassword)
 * @method static string getPasswordAlgorithm()
 * @method static array getPasswordOptions()
 * @method static string getKey()
 * @method static string encrypt(string $value)
 * @method static string decrypt(string $encrypted)
 * @method static string random(int $length = 32)
 * @method static string randomBytes(int $length = 32)
 * @method static string hash(string $value, string $algo = 'sha256')
 * @method static string hmac(string $value, string $algo = 'sha256')
 * @method static string encryptArray(array $data) 
 * @method static array decryptArray(string $encrypted) 
 * @method static string encryptString(string $value)
 * @method static string decryptString(string $encrypted)
 * @method static bool isEqual(string $known, string $user) 
 * @method static string bcrypt(string $password, array $options = [])
 * @method static string argon2i(string $password, array $options = [])
 * @method static string argon2id(string $password, array $options = [])
 * @method static string uuid()
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Hash extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseHash::class;
    }
}
