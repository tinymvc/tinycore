<?php

namespace Spark\Http\Session;

use SessionHandlerInterface;
use Spark\Foundation\Application;
use Spark\Http\Session\Handler\{DatabaseHandler, RedisHandler, FileHandler};
use function in_array;
use function is_array;
use function is_string;

class SessionHandler
{
    /**
     * Starts the session if it hasn't been started yet.
     *
     * @return void
     */
    public static function start(): void
    {
        if (isset(Application::$app) && Application::$app->isTesting()) {
            $_SESSION ??= [];
            return;
        }

        if (!is_web() || session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            self::configure(); // Apply session configuration before starting the session

            session_start(); // Start the session after configuration
        }
    }

    /**
     * Applies session ini/cookie settings and registers the configured
     * storage handler before the session is started.
     *
     * @return void
     */
    private static function configure(): void
    {
        $lifetime = max(1, (int) config('session.lifetime', 120)); // minutes

        ini_set('session.gc_maxlifetime', (string) ($lifetime * 60));
        ini_set('session.gc_probability', (string) config('session.gc_probability', 1));
        ini_set('session.gc_divisor', (string) config('session.gc_divisor', 100));

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        $cookie = (array) config('session.cookie_settings', []);

        session_set_cookie_params([
            'lifetime' => config('session.expire_on_close', false) ? 0 : $lifetime * 60,
            'path' => $cookie['path'] ?? '/',
            'domain' => $cookie['domain'] ?? '',
            'secure' => $cookie['secure'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443),
            'httponly' => $cookie['http_only'] ?? $cookie['httponly'] ?? true,
            'samesite' => $cookie['same_site'] ?? $cookie['samesite'] ?? 'Lax',
        ]);

        $cookieName = config('session.cookie_name');
        if (is_string($cookieName) && $cookieName !== '') {
            session_name($cookieName);
        }

        $handler = self::resolveHandler();
        if ($handler instanceof SessionHandlerInterface) {
            session_set_save_handler($handler, true);
        }
    }

    /**
     * Builds the configured session storage handler instance.
     *
     * @return SessionHandlerInterface The configured storage handler.
     */
    private static function resolveHandler(): SessionHandlerInterface
    {
        $handler = (string) (config('session.default') ?: config('session.handler') ?: config('session.driver', 'file'));
        $config = config("session.connections.$handler");

        if (!is_array($config)) {
            if (!in_array(strtolower($handler), ['database', 'redis', 'file'], true)) {
                throw new \InvalidArgumentException("Session connection [{$handler}] is not defined in session.connections.");
            }

            $config = [];
        }

        $driver = strtolower((string) ($config['driver'] ?? $handler));
        $config['lifetime'] ??= config('session.lifetime', 120);

        return match ($driver) {
            'database' => new DatabaseHandler($config),
            'redis' => new RedisHandler($config),
            'file' => new FileHandler($config),
            default => throw new \InvalidArgumentException("Unsupported session handler [{$driver}]."),
        };
    }
}