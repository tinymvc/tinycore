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
     * @return mixed The response from the next middleware or handler.
     * @throws TooManyRequests If the request limit is exceeded.
     */
    public function handle(Request $request, \Closure $next, ...$args): mixed
    {
        $attempts = $args[0] ?? 50; // Max requests in the time frame
        $duration = $args[1] ?? 1; // Duration in minutes for the time frame
        $suffix = $args[2] ?? ''; // Optional suffix for cache key differentiation

        if (!$this->authorizeCurrentRequest($request, $duration, $attempts, $suffix)) {
            throw new TooManyRequests('Too Many Requests', 429);
        }

        return $next($request);
    }

    /**
     * Authorize the current request based on IP address and request limits.
     *
     * @param Request $request The current request.
     * @param int $minute The time frame in minutes.
     * @param int $attempts The maximum number of requests allowed in the time frame.
     * @param string $suffix An optional suffix to differentiate cache keys.
     *
     * @return bool True if the request is authorized, false otherwise.
     */
    private function authorizeCurrentRequest(Request $request, int $minute, int $attempts, string $suffix): bool
    {
        $ip = $request->ip();
        $path = $request->getPath();

        if (!$ip) {
            return false;
        }

        $config = array_merge([
            'cache_name' => 'throttle_incoming_requests',
            'cache_dir' => null,
        ], $this->config);

        $cache = new Cache($config['cache_name'] . $suffix, $config['cache_dir']);
        $key = md5("$path$ip");

        $now = time();
        $windowStart = $now - $minute * 60;

        $timestamps = $cache->get($key, true) ?: [];

        $timestamps = array_filter($timestamps, fn($timestamp) => $timestamp > $windowStart);

        if (count($timestamps) >= $attempts) {
            return false;
        }

        $timestamps[] = $now;

        $cache->store($key, $timestamps, sprintf("+%d minutes", $minute + 1));

        return true;
    }
}
