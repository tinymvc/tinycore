<?php

namespace Spark\Queue;

use InvalidArgumentException;
use RuntimeException;

/** A local, atomic deployment marker shared by the CLI and supervised daemon. */
class RestartSignal
{
    private readonly ?string $initialToken;

    public function __construct(private readonly string $file)
    {
        if (str_contains($file, "\0") || !preg_match('~\A(?:/|[A-Za-z]:[\\\\/])~', $file)) {
            throw new InvalidArgumentException('Queue restart_file must be an absolute local file path.');
        }
        $this->initialToken = $this->readToken();
    }

    public static function configuredPath(): string
    {
        return (string) config('queue.restart_file', storage_dir('queue.restart'));
    }

    public function requested(): bool
    {
        $token = $this->readToken();
        return $token !== null && $token !== $this->initialToken;
    }

    public function request(): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create Queue restart directory.');
        }

        $temporary = @tempnam($directory, '.queue-restart-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create Queue restart marker.');
        }
        try {
            $token = bin2hex(random_bytes(16));
            if (file_put_contents($temporary, $token) !== strlen($token) || !@rename($temporary, $this->file)) {
                throw new RuntimeException('Cannot write Queue restart marker.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function readToken(): ?string
    {
        clearstatcache(true, $this->file);
        if (!file_exists($this->file)) {
            return null;
        }
        $token = @file_get_contents($this->file, false, null, 0, 33);
        if ($token === false) {
            throw new RuntimeException('Cannot read Queue restart marker. Run deployment commands as the daemon user.');
        }
        if (!preg_match('/\A[0-9a-f]{32}\z/', $token)) {
            throw new RuntimeException('Invalid Queue restart marker.');
        }
        return $token;
    }
}
