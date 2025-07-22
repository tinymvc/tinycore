<?php

namespace Spark\Contracts;

use Closure;

/**
 * Interface PipeInterface
 *
 * This interface defines the contract for a pipe in the pipeline.
 * A pipe is responsible for processing the payload and passing it to the next pipe.
 */
interface PipeInterface
{
    /**
     * Handle the payload and pass it to the next pipe.
     *
     * @param mixed $payload The data to be processed.
     * @param Closure $next The next pipe in the pipeline.
     *
     * @return mixed The processed data.
     */
    public function handle(mixed $payload, Closure $next): mixed;
}