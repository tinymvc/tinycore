<?php

namespace Spark\Facades;

use Spark\Storage\Storage as StorageInstance;

/**
 * Static access to the default storage. storage($name) selects another configured storage.
 *
 * @method static StorageInstance disk(?string $name = null)
 * @method static StorageInstance build(array $config)
 * @method static bool put(string $path, string $contents)
 * @method static string putFile(string $directory, string $localPath)
 * @method static string putFileAs(string $directory, string $localPath, string $name)
 * @method static string get(string $path)
 * @method static bool exists(string $path)
 * @method static bool missing(string $path)
 * @method static bool delete(string|array $paths)
 * @method static string url(string $path)
 * @method static string path(string $path = '')
 * @method static string temporaryUrl(string $path, int|\DateTimeInterface $expires = 300)
 * @method static array files(string $directory = '', bool $recursive = false)
 * @method static array allFiles(string $directory = '')
 * @method static int size(string $path)
 * @method static string mimeType(string $path)
 * @method static int lastModified(string $path)
 * @method static bool copy(string $from, string $to)
 * @method static bool move(string $from, string $to)
 * @method static \Spark\Storage\Uploader uploader(string $uploadTo = '', array $extensions = [], ?bool $multiple = null, ?int $maxSize = 2048, null|float|array $resize = null, ?array $resizes = null, ?int $compress = null)
 */
class Storage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StorageInstance::class;
    }
}
