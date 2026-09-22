<?php

namespace Spark\Facades;

/**
 * Static access to the default disk. disk($name) selects another configured disk.
 *
 * @method static \Spark\Storage\Disk disk(?string $name = null)
 * @method static \Spark\Storage\Disk build(array $config)
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
class Disk extends Facade
{
    // Selecting a named/on-demand disk must not construct the default disk first.
    public static function disk(?string $name = null): \Spark\Storage\Disk
    {
        return \Spark\Storage\Disk::disk($name);
    }

    public static function build(array $config): \Spark\Storage\Disk
    {
        return \Spark\Storage\Disk::build($config);
    }

    protected static function getFacadeAccessor(): string
    {
        return \Spark\Storage\Disk::class;
    }
}
