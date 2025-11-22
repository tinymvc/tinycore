<?php

namespace Spark\Facades;

use Spark\Database\Model;
use Spark\Http\Auth as BaseAuth;

/**
 * Facade Auth
 * 
 * This class serves as a facade for the authentication system, providing a static interface to the underlying Auth class.
 * It allows easy access to authentication methods such as login, logout, and user retrieval 
 * without needing to instantiate the Auth class directly.
 * 
 * @method static ?Model getUser()
 * @method static int getId()
 * @method static int id()
 * @method static \App\Models\User|mixed user(?string $key = null, $default = null)
 * @method static string getLoginRoute()
 * @method static string getRedirectRoute()
 * @method static bool hasId()
 * @method static bool attempt(array $credentials)
 * @method static bool isGuest()
 * @method static bool isLogged()
 * @method static void configure(array $config)
 * @method static void login(Model $user, bool $remember = false)
 * @method static void logout()
 * @method static void check()
 * @method static void clearCache()
 * @method static void refresh()
 * @method static string getJwtToken(Model $user, array $payload = [])
 * @method static string createJwtToken(array $payload = [])
 * 
 * @package Spark\Facades
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Auth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseAuth::class;
    }
}
