<?php

namespace Spark\Storage;

use InvalidArgumentException;
use RuntimeException;
use Spark\Contracts\Utils\UploaderUtilDriverInterface;
use Spark\Support\Traits\Macroable;
use function is_array;
use function strlen;

/** A named local or S3 disk. All paths are relative to the selected disk. */
class Disk implements Contracts\DiskContract
{
    use Macroable;

    private LocalStorage|S3Storage $storage;
    private LocalStorage|S3UploaderDriver $driver;

    /** Pass explicit config for an on-demand disk, or resolve config/disk.php. */
    public function __construct(?string $name = null, ?array $config = null)
    {
        if ($config === null) {
            $name ??= config('disk.default', 'local');
            $config = config("disk.disks.$name");
            if (!is_array($config)) {
                throw new InvalidArgumentException("Disk [$name] is not configured.");
            }
        }

        switch ($config['driver'] ?? 'local') {
            case 'local':
                $this->storage = new LocalStorage($config['root'] ?? '', $config['url'] ?? null, $config['visibility'] ?? 'private');
                $this->driver = $this->storage;
                break;
            case 's3':
                $this->storage = new S3Storage(
                    accessKey: $config['key'] ?? '',
                    secretKey: $config['secret'] ?? '',
                    endpoint: $config['endpoint'] ?? null,
                    bucket: $config['bucket'] ?? null,
                    usePathStyleEndpoint: filter_var($config['use_path_style_endpoint'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    timeout: $config['timeout'] ?? 300,
                    region: $config['region'] ?? null,
                    sessionToken: $config['token'] ?? null,
                    url: $config['url'] ?? null,
                    listVersion: $config['list_version'] ?? 2,
                );
                $this->driver = new S3UploaderDriver($this->storage, $config['acl'] ?? null);
                break;
            default:
                throw new InvalidArgumentException('Unsupported disk driver: ' . $config['driver']);
        }
    }

    /** Select a configured disk without changing the default or other instances. */
    public static function disk(?string $name = null): self
    {
        return new self($name);
    }

    /** Create an on-demand disk without adding a named configuration. */
    public static function build(array $config): self
    {
        return new self(config: $config);
    }

    /** Write string contents; failures throw rather than silently losing data. */
    public function put(string $path, string $contents): bool
    {
        $path = StoragePath::normalize($path);
        if ($this->storage instanceof LocalStorage) {
            return $this->storage->put($path, $contents);
        }

        $temporary = tmpfile();
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary upload file.');
        }

        try {
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($temporary, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot stage disk contents.');
                }

                $offset += $written;
            }

            if (!fflush($temporary)) {
                throw new RuntimeException('Cannot flush upload contents.');
            }

            return $this->driver->upload(stream_get_meta_data($temporary)['uri'], $path);
        } finally {
            fclose($temporary);
        }
    }

    /** Copy an existing local file to an exact disk path; returns the stored path. */
    public function putFileAs(string $directory, string $localPath, string $name): string
    {
        $directory = StoragePath::normalize($directory, true);
        $name = StoragePath::normalize($name);

        if (str_contains($name, '/')) {
            throw new InvalidArgumentException('The filename must not contain a directory.');
        }

        $key = ltrim("$directory/$name", '/');
        if (!$this->driver->upload($localPath, $key)) {
            throw new RuntimeException('Disk upload failed.');
        }

        return $key;
    }

    /** Copy a local file with a random filename, preserving its extension. */
    public function putFile(string $directory, string $localPath): string
    {
        $extension = pathinfo($localPath, PATHINFO_EXTENSION);
        return $this->putFileAs($directory, $localPath, bin2hex(random_bytes(16)) . ($extension === '' ? '' : ".$extension"));
    }

    /** Read contents into memory. Use temporaryUrl() for large private S3 downloads. */
    public function get(string $path): string
    {
        $path = StoragePath::normalize($path);
        return $this->storage instanceof LocalStorage ? $this->storage->get($path) : $this->storage->getFile($path);
    }

    public function exists(string $path): bool
    {
        return $this->storage->exists(StoragePath::normalize($path));
    }

    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    /** Delete exact file keys only. Missing files succeed; arrays are not atomic. */
    public function delete(string|array $paths): bool
    {
        $keys = array_map(StoragePath::normalize(...), (array) $paths);
        foreach ($keys as $key) {
            if (!$this->driver->delete($key)) {
                throw new RuntimeException('Disk deletion failed.');
            }
        }
        return true;
    }

    /** Public URL construction does not change an object's access permissions. */
    public function url(string $path): string
    {
        return $this->storage->getPublicUrl(StoragePath::normalize($path));
    }

    /** Filesystem path for local disks only. */
    public function path(string $path = ''): string
    {
        if (!$this->storage instanceof LocalStorage) {
            throw new RuntimeException('S3 disks have object keys, not local filesystem paths.');
        }
        return $this->storage->path($path);
    }

    /** Signed S3 GET URL; expiry is seconds (1–604800), or an absolute date/time. */
    public function temporaryUrl(string $path, int|\DateTimeInterface $expires = 300): string
    {
        if (!$this->storage instanceof S3Storage) {
            throw new RuntimeException('Temporary URLs are supported only by S3 disks.');
        }
        $seconds = $expires instanceof \DateTimeInterface ? $expires->getTimestamp() - time() : $expires;
        return $this->storage->temporaryUrl(StoragePath::normalize($path), $seconds);
    }

    public function size(string $path): int
    {
        return $this->storage->metadata(StoragePath::normalize($path))['size'];
    }

    public function mimeType(string $path): string
    {
        return $this->storage->metadata(StoragePath::normalize($path))['mime_type'];
    }

    public function lastModified(string $path): int
    {
        return $this->storage->metadata(StoragePath::normalize($path))['last_modified'];
    }

    /** List relative file keys; S3 pagination is consumed automatically. */
    public function files(string $directory = '', bool $recursive = false): array
    {
        $directory = StoragePath::normalize($directory, true);
        if ($this->storage instanceof LocalStorage) {
            return $this->storage->files($directory, $recursive);
        }

        $prefix = $directory === '' ? '' : "$directory/";
        $files = $seen = [];
        $marker = '';

        do {
            $page = $this->storage->listFiles(1000, $prefix, $marker);
            foreach ($page['files'] as $file) {
                $key = $file['key'];
                if (!str_starts_with($key, $prefix) || str_ends_with($key, '/')) {
                    continue;
                }
                // Keep the listing usable with every other disk operation.
                try {
                    if (StoragePath::normalize($key) !== $key) {
                        continue;
                    }
                } catch (InvalidArgumentException) {
                    continue;
                }

                if ($recursive || !str_contains(substr($key, strlen($prefix)), '/')) {
                    $files[] = $key;
                }
            }

            if (!$page['is_truncated']) {
                break;
            }

            $marker = $page['next_marker'];
            if (isset($seen[$marker])) {
                throw new RuntimeException('S3 returned a repeated pagination marker.');
            }

            $seen[$marker] = true;
        } while (true);

        sort($files, SORT_STRING);
        return array_values(array_unique($files));
    }

    public function allFiles(string $directory = ''): array
    {
        return $this->files($directory, true);
    }

    /** Copies within this disk. Local copies stream; S3 copies run on the server. */
    public function copy(string $from, string $to): bool
    {
        $from = StoragePath::normalize($from);
        $to = StoragePath::normalize($to);

        if ($from === $to) {
            return $this->exists($from);
        }

        if ($this->storage instanceof LocalStorage) {
            return $this->driver->upload($this->path($from), $to);
        }

        return $this->driver->copy($from, $to);
    }

    /** Copy then delete; deletion failure can leave both files. */
    public function move(string $from, string $to): bool
    {
        $from = StoragePath::normalize($from);
        $to = StoragePath::normalize($to);

        if ($from === $to) {
            return $this->exists($from);
        }

        return $this->copy($from, $to) && $this->delete($from);
    }

    /** Uploader driver for integrations that already manage relative destinations. */
    public function getDriver(): UploaderUtilDriverInterface
    {
        return $this->driver;
    }

    /** Validate/process HTTP uploads using this disk and return disk-relative keys. */
    public function uploader(
        string $uploadTo = '',
        array $extensions = [],
        ?bool $multiple = null,
        ?int $maxSize = 2048,
        null|float|array $resize = null,
        ?array $resizes = null,
        ?int $compress = null,
    ): Uploader {
        $uploadTo = StoragePath::normalize($uploadTo, true);
        $staging = storage_dir('temp/disk-uploads');

        if (!is_dir($staging) && !@mkdir($staging, 0700, true) && !is_dir($staging)) {
            throw new RuntimeException('Cannot create the private disk upload staging directory.');
        }

        return new Uploader(
            uploadTo: $uploadTo,
            uploadDir: $staging,
            extensions: $extensions,
            multiple: $multiple,
            maxSize: $maxSize,
            resize: $resize,
            resizes: $resizes,
            compress: $compress,
            driver: $this->driver,
            relativeTo: $staging
        );
    }
}
