<?php

namespace Spark\Foundation\Http\Middlewares;

use Spark\Contracts\Http\MiddlewareInterface;
use Spark\Foundation\Exceptions\TooManyRequests;
use Spark\Http\Request;
use Spark\Cache\Cache;
use function count;
use function is_array;
use function is_numeric;
use function max;
use function md5;
use function sprintf;

/**
 * Middleware to throttle requests based on IP address.
 * 
 * Usage: Throttle requests to a maximum number within a specified time frame.
 * Parameters:
 *      - $attempts: Maximum number of requests allowed in the time frame (default: 50)
 *      - $minutes: Time frame in minutes (default: 1)
 *      - $suffix: Optional suffix to differentiate cache keys (default: '')
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
abstract class ThrottleIncomingRequests implements MiddlewareInterface
{
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
        $attempts = $this->normalizePositiveInt($args[0] ?? 50, 50); // Max requests in the time frame
        $duration = $this->normalizePositiveInt($args[1] ?? 1, 1); // Duration in minutes for the time frame
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
     * @param int $duration The time frame in minutes for which the request count is tracked.
     * @param int $attempts The maximum number of requests allowed in the time frame.
     * @param string $suffix An optional suffix to differentiate cache keys.
     *
     * @return bool True if the request is authorized, false otherwise.
     */
    private function authorizeCurrentRequest(Request $request, int $duration, int $attempts, string $suffix): bool
    {
        $ip = (string) $request->ip() ?: '127.0.0.1';
        $path = trim($request->getPath(), '/');

        $cache = new Cache('th:requests');
        $identifier = md5("{$request->getMethod()}|{$path}|{$ip}|{$suffix}");
        $key = "{$duration}m:{$attempts}:{$identifier}";

        $now = time();
        $windowStart = $now - ($duration * 60);

        $timestamps = $cache->retrieve($key, eraseExpired: true);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        $timestamps = array_values(
            array_filter(
                $timestamps,
                fn($timestamp) => is_numeric($timestamp) && (int) $timestamp > $windowStart
            )
        );

        if (count($timestamps) >= $attempts) {
            return false;
        }

        $timestamps[] = $now;

        $cache->store($key, $timestamps, sprintf('+%d minutes', $duration));

        return true;
    }

    /**
     * Normalize throttle config values.
     */
    private function normalizePositiveInt(mixed $value, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        $value = (int) $value;
        return max(1, $value);
    }
}
