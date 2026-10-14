<?php

namespace Spark\Queue\Contracts;

interface JobInterface
{
    /**
     * Handle the job.
     *
     * Implementations may declare additional optional parameters.
     *
     * @param mixed ...$args
     * @return void
     */
    public function handle(mixed ...$args): void;
}