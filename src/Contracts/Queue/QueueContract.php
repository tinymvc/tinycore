<?php

namespace Spark\Contracts\Queue;

use Spark\Queue\Job;

interface QueueContract
{
    public function addJob(Job $job): void;

    public function run(): void;

}