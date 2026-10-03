<?php

namespace Spark\Http\Session\Handler;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Spark\Utils\File;
use function is_resource;
use function strlen;

/** Stores sessions under an exclusive lock until close() or an ID change. */
class FileHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private string $path;

    private int $lifetime;

    /** @var resource|null */
    private $handle = null;

    private ?string $id = null;

    public function __construct(array $config = [])
    {
        $this->path = rtrim((string) ($config['path'] ?? storage_dir('framework/sessions')), '/\\');
        $this->lifetime = max(1, (int) ($config['lifetime'] ?? 120)) * 60;
    }

    public function open(string $path, string $name): bool
    {
        return File::ensureDirectoryExists($this->path, 0700);
    }

    public function close(): bool
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
        $this->id = null;

        return true;
    }

    public function read(string $id): string|false
    {
        if (!$this->acquire($id)) {
            return false;
        }

        $stat = fstat($this->handle);

        if ($stat === false || $stat['mtime'] <= time() - $this->lifetime) {
            return '';
        }

        rewind($this->handle);

        return stream_get_contents($this->handle);
    }

    public function write(string $id, string $data): bool
    {
        if (!$this->acquire($id)) {
            return false;
        }

        if (!rewind($this->handle) || !ftruncate($this->handle, 0)) {
            return false;
        }

        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($this->handle, substr($data, $offset));

            if ($written === false || $written === 0) {
                return false;
            }

            $offset += $written;
        }

        return fflush($this->handle) && touch($this->filePath($id));
    }

    public function destroy(string $id): bool
    {
        if (!$this->acquire($id)) {
            return false;
        }

        $deleted = unlink($this->filePath($id));
        $this->close();

        return $deleted;
    }

    public function gc(int $max_lifetime): int|false
    {
        $count = 0;

        foreach (glob($this->path . '/sess_*') ?: [] as $file) {
            $handle = @fopen($file, 'r+b');

            if ($handle === false) {
                continue;
            }

            try {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    $stat = fstat($handle);

                    if ($stat !== false && $stat['mtime'] <= time() - $max_lifetime && @unlink($file)) {
                        $count++;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return $count;
    }

    public function validateId(string $id): bool
    {
        $file = $this->filePath($id);
        clearstatcache(true, $file);

        return is_file($file) && filemtime($file) > time() - $this->lifetime;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    private function acquire(string $id): bool
    {
        if ($this->id === $id && is_resource($this->handle)) {
            return true;
        }

        $this->close();
        $file = $this->filePath($id);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $handle = @fopen($file, 'c+b');

            if ($handle === false) {
                return false;
            }

            if (!flock($handle, LOCK_EX)) {
                fclose($handle);

                return false;
            }

            clearstatcache(true, $file);
            $current = @stat($file);
            $opened = fstat($handle);

            // GC or destroy() may have unlinked the file while this reader waited.
            if ($current !== false && $opened !== false && $current['ino'] === $opened['ino']) {
                @chmod($file, 0600);
                $this->handle = $handle;
                $this->id = $id;

                return true;
            }

            fclose($handle);
        }

        return false;
    }

    private function filePath(string $id): string
    {
        if (!preg_match('/\\A[a-zA-Z0-9,-]{1,256}\\z/', $id)) {
            throw new \InvalidArgumentException('Invalid session ID.');
        }

        return $this->path . '/sess_' . $id;
    }
}
