<?php

namespace Spark\Foundation\Http\Middlewares;

use Spark\Contracts\Http\MiddlewareInterface;
use Spark\Foundation\Exceptions\TooManyRequests;
use Spark\Http\Request;
use Spark\Utils\Cache;

/**
 * Middleware to throttle requests based on IP address.
 * 
 * Usage: Throttle requests to a maximum number within a specified time frame.
 * Parameters:
 *      - $minute: Time frame in minutes (default: 1)
 *      - $requests: Maximum number of requests allowed in the time frame (default: 50)
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
abstract class ThrottleIncomingRequests implements MiddlewareInterface
{
    /** @var array<string, mixed> The cache configuration */
    protected array $config = [];

    /**
     * Throttle incoming requests.
     *
     * This middleware checks the number of requests made by a client IP address
     * within a specified time frame. If the limit is exceeded, it returns a 429
     * Too Many Requests response.
     *
     * @param Request $request The current request.
     *
     * @return mixed The response when the token is invalid, current request otherwise.
     */
    public function handle(Request $request, \Closure $next, ...$args): mixed
    {
        $minute = $args[0] ?? 1;
        $requests = $args[1] ?? 50;

        if (!$this->authorizeCurrentRequest($request->ip(), $minute, $requests)) {
            throw new TooManyRequests('Too Many Requests', 429);
        }

        return $next($request);
    }

    /**
     * Authorize the current request based on IP address and request limits.
     *
     * @param string|null $ip The client's IP address.
     * @param int $minute The time frame in minutes.
     * @param int $requests The maximum number of requests allowed in the time frame.
     *
     * @return bool True if the request is authorized, false otherwise.
     */
    private function authorizeCurrentRequest(?string $ip, int $minute, int $requests): bool
    {
        if (!$ip) {
            return false;
        }

        $config = array_merge([
            'cache_name' => 'throttle_incoming_requests',
            'cache_dir' => null,
        ], $this->config);

        $cache = new Cache($config['cache_name'], $config['cache_dir']);
        $key = 'throttle_' . md5($ip);

        $now = time();
        $windowStart = $now - $minute * 60;

        $timestamps = $cache->get($key) ?: [];

        $timestamps = array_filter($timestamps, fn($timestamp) => $timestamp > $windowStart);

        if (count($timestamps) >= $requests) {
            return false;
        }

        $timestamps[] = $now;

        $cache->store($key, $timestamps, sprintf("+%d minute%s", $minute + 1, $minute > 0 ? 's' : ''));

        return true;
    }
}