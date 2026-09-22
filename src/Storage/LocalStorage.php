<?php

namespace Spark\Storage;

use InvalidArgumentException;
use RuntimeException;
use Spark\Contracts\Utils\UploaderUtilDriverInterface;
use function in_array;
use function is_resource;

/** Local disk storage. Symlinks below the configured root are not followed. */
class LocalStorage implements UploaderUtilDriverInterface
{
    private string $root;
    private int $directoryMode;
    private int $fileMode;

    public function __construct(string $root, private ?string $url = null, string $visibility = 'private')
    {
        if (
            $root === '' || str_contains($root, "\0") || str_contains($root, '://')
            || !preg_match('~^(?:/|[a-zA-Z]:[/\\\\])~', $root)
        ) {
            throw new InvalidArgumentException('Local disk root must be an absolute filesystem path.');
        }

        if (!in_array($visibility, ['private', 'public'], true)) {
            throw new InvalidArgumentException('Visibility must be private or public.');
        }

        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->directoryMode = $visibility === 'public' ? 0755 : 0700;
        $this->fileMode = $visibility === 'public' ? 0644 : 0600;
    }

    public function path(string $key = ''): string
    {
        $key = StoragePath::normalize($key, true);
        clearstatcache();
        $path = $this->root;

        foreach ($key === '' ? [] : explode('/', $key) as $part) {
            $path .= "/$part";
            if (is_link($path)) {
                throw new RuntimeException('Disk paths cannot traverse symbolic links.');
            }
        }

        return $path === '' ? '/' : $path;
    }

    public function upload(string $filepath, string $destination): bool
    {
        if (str_contains($filepath, '://') || !is_file($filepath) || !is_readable($filepath)) {
            throw new InvalidArgumentException('Upload source must be a readable local file.');
        }

        $stream = @fopen($filepath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot open upload source.');
        }

        try {
            return $this->write($destination, $stream);
        } finally {
            fclose($stream);
        }
    }

    public function put(string $key, string $contents): bool
    {
        return $this->write($key, $contents);
    }

    /** Publish a complete file with an atomic rename on the same filesystem. */
    private function write(string $key, mixed $contents): bool
    {
        $path = $this->path(StoragePath::normalize($key));
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, $this->directoryMode, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create disk directory.');
        }

        $this->path($key);
        $temporary = "$directory/.spark-" . bin2hex(random_bytes(16));
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot create disk file.');
        }

        try {
            if (!@chmod($temporary, $this->fileMode)) {
                throw new RuntimeException('Cannot set disk file permissions.');
            }

            if (is_resource($contents)) {
                if (stream_copy_to_stream($contents, $stream) === false) {
                    throw new RuntimeException('Cannot copy disk file.');
                }
            } else {
                $offset = 0;
                while ($offset < strlen($contents)) {
                    $written = fwrite($stream, substr($contents, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Cannot write disk file.');
                    }
                    $offset += $written;
                }
            }

            if (!fflush($stream)) {
                throw new RuntimeException('Cannot flush disk file.');
            }

            fclose($stream);
            $stream = null;
            $this->path($key);

            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Cannot publish disk file.');
            }
            return true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function get(string $key): string
    {
        $path = $this->path(StoragePath::normalize($key));
        $contents = is_file($path) ? @file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException('Cannot read disk file.');
        }
        return $contents;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path(StoragePath::normalize($key)));
    }

    public function delete(string $destination): bool
    {
        $path = $this->path(StoragePath::normalize($destination));
        if (!file_exists($path)) {
            return true;
        }

        if (!is_file($path) || !@unlink($path)) {
            throw new RuntimeException('Cannot delete disk file.');
        }
        return true;
    }

    public function metadata(string $key): array
    {
        $path = $this->path(StoragePath::normalize($key));
        $stat = is_file($path) ? @stat($path) : false;
        if ($stat === false) {
            throw new RuntimeException('Cannot inspect disk file.');
        }

        return [
            'size' => $stat['size'],
            'last_modified' => $stat['mtime'],
            'mime_type' => function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream'
        ];
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $directory = StoragePath::normalize($directory, true);
        $path = $this->path($directory);

        if (!file_exists($path)) {
            return [];
        }

        if (!is_dir($path)) {
            throw new RuntimeException('Listing path must be a directory.');
        }

        $result = [];
        foreach (new \FilesystemIterator($path) as $file) {
            if ($file->isLink()) {
                continue;
            }

            $key = ltrim("$directory/" . $file->getFilename(), '/');
            if ($file->isFile()) {
                $result[] = $key;
            } elseif ($recursive && $file->isDir()) {
                $result = [...$result, ...$this->files($key, true)];
            }
        }

        sort($result, SORT_STRING);
        return $result;
    }

    public function getPublicUrl(string $key): string
    {
        if (!$this->url) {
            throw new RuntimeException('This disk has no public URL. Serve private files through an authorized controller.');
        }

        return rtrim($this->url, '/') . '/' . StoragePath::encode(StoragePath::normalize($key));
    }
}
