<?php

namespace Spark\Http\Client;

use Spark\Http\Client\Contracts\HttpRequestContract;
use Spark\Telemetry;
use Throwable;

/** @internal Shared telemetry for fluent, prepared, and pooled HTTP requests. */
final class HttpTelemetry
{
    public static function record(
        HttpRequestContract $request,
        ?array $response,
        float $startedAt,
        ?Throwable $error = null,
        ?float $durationMs = null,
    ): void {
        if (!Telemetry::isListening('http.request')) {
            return;
        }

        $durationMs ??= round((microtime(true) - $startedAt) * 1000, 2);

        // Preserve the pre-telemetry positional event contract.
        Telemetry::emit(
            'http.request',
            $request->getFullUrl(...),
            fn() => [
                'method' => $request->getMethod(),
                'url' => $request->getUrl(),
                'params' => $request->getParams(),
                'body' => $request->getData(),
                'headers' => $request->getHeaders(),
            ],
            fn() => $response ?? [
                'body' => '',
                'status' => 0,
                'lastUrl' => $request->getFullUrl(),
                'length' => 0,
                'headers' => [],
            ],
            $durationMs,
            $error,
        );
    }
}
