<?php

namespace Spark\Contracts\Queue;

use Closure;
use DateTime;

interface JobContract
{
    public function repeat(?string $repeat): self;

    public function priority(int $priority): self;

    public function schedule(string|DateTime $scheduledTime): self;

    public function catch(Closure $closure): self;

    public function before(Closure $closure): self;

    public function after(Closure $closure): self;

    public function handle(): void;

    public function getScheduledTime(): DateTime;

    public function dispatch(): void;
}