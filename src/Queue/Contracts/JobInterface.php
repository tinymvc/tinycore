<?php

namespace Queue\Contracts;

interface JobInterface
{
    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle(): void;
}