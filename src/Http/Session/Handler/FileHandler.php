<?php

namespace Spark\Http\Session\Handler;

use SessionHandlerInterface;
use Spark\Utils\File;
use function is_file;
use function file_exists;
use function filemtime;
use function glob;
use function time;
use function unlink;

/**
 * Class FileHandler
 *
 * Stores session data as individual files, holding an exclusive lock for
 * the lifetime of the request to avoid read/write races on the same session.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class FileHandler implements SessionHandlerInterface
{
    private string $path;

    /** @var resource|null */
    private $handle = null;

    public function __construct(array $config = [])
    {
        $this->path = rtrim((string) ($config['path'] ?? sys_get_temp_dir()), '/');
    }

    public function open(string $path, string $name): bool
    {
        return File::ensureDirectoryExists($this->path);
    }

    public function close(): bool
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }

        return true;
    }

    public function read(string $id): string|false
    {
        $this->handle = fopen($this->filePath($id), 'c+b');

        if ($this->handle === false) {
            return '';
        }

        flock($this->handle, LOCK_EX);

        $data = stream_get_contents($this->handle);

        return $data === false ? '' : $data;
    }

    public function write(string $id, string $data): bool
    {
        if ($this->handle === null) {
            $this->handle = fopen($this->filePath($id), 'c+b');
            if ($this->handle === false) {
                return false;
            }
            flock($this->handle, LOCK_EX);
        }

        rewind($this->handle);
        ftruncate($this->handle, 0);
        $written = fwrite($this->handle, $data);
        fflush($this->handle);

        return $written !== false;
    }

    public function destroy(string $id): bool
    {
        $file = $this->filePath($id);

        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }

        return !file_exists($file) || unlink($file);
    }

    public function gc(int $max_lifetime): int|false
    {
        $count = 0;

        foreach (glob($this->path . '/sess_*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - $max_lifetime) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function filePath(string $id): string
    {
        return $this->path . '/sess_' . $id;
    }
}