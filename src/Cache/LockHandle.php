<?php

namespace Spark\Cache;

use Spark\Cache\Contracts\CacheStorageContract;
use Spark\Cache\Exceptions\LockTimeoutException;

/** One resource and owner. The repository may be shared; independent operations use separate handles. */
class LockHandle
{
    private readonly string $owner;

    public function __construct(
        private readonly CacheStorageContract $storage,
        private readonly string $name,
        private readonly int $seconds = 10,
        ?string $owner = null,
    ) {
        if ($name === '' || $seconds < 1 || $owner === '') {
            throw new \InvalidArgumentException('Lock names and owners must be non-empty, and the lease must be at least one second.');
        }
        $this->owner = $owner ?? bin2hex(random_bytes(20));
    }

    public function acquire(): bool
    {
        return $this->storage->lock($this->name, $this->owner, $this->seconds, 0);
    }

    public function get(?callable $callback = null): mixed
    {
        if (!$this->acquire()) {
            return false;
        }
        return $callback === null ? true : $this->runCallback($callback);
    }

    public function block(int $seconds, ?callable $callback = null): mixed
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Lock wait time must be non-negative.');
        }
        // Use a monotonic deadline, including a single immediate attempt for block(0).
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        while (!$this->acquire()) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                throw new LockTimeoutException("Failed to acquire lock for key: {$this->name}");
            }
            usleep((int) min(50000, max(1, intdiv($remaining, 1000))));
        }
        return $callback === null ? true : $this->runCallback($callback);
    }

    private function runCallback(callable $callback): mixed
    {
        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    public function release(): bool
    {
        return $this->storage->unlock($this->name, $this->owner);
    }

    public function forceRelease(): bool
    {
        return $this->storage->forceUnlock($this->name);
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function isOwnedByCurrentProcess(): bool
    {
        return $this->storage->ownsLock($this->name, $this->owner);
    }
}
