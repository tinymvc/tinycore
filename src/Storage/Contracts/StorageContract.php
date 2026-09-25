<?php

namespace Spark\Storage\Contracts;

use DateTimeInterface;
use Spark\Contracts\Utils\UploaderUtilDriverInterface;
use Spark\Storage\Uploader;

/**
 * Application-facing operations on a selected disk.
 * Paths are disk-relative keys. Failures throw; missing-file deletion succeeds.
 * URL construction does not grant access. path() is local-only and temporaryUrl() is S3-only.
 */
interface StorageContract
{
    public function put(string $path, string $contents): bool;

    public function putFile(string $directory, string $localPath): string;

    public function putFileAs(string $directory, string $localPath, string $name): string;

    public function get(string $path): string;

    public function exists(string $path): bool;

    public function missing(string $path): bool;

    public function delete(string|array $paths): bool;

    public function url(string $path): string;

    public function path(string $path = ''): string;

    public function temporaryUrl(string $path, int|DateTimeInterface $expires = 300): string;

    public function size(string $path): int;

    public function mimeType(string $path): string;

    public function lastModified(string $path): int;

    public function files(string $directory = '', bool $recursive = false): array;

    public function allFiles(string $directory = ''): array;

    public function copy(string $from, string $to): bool;

    public function move(string $from, string $to): bool;

    public function getDriver(): UploaderUtilDriverInterface;

    public function uploader(
        string $uploadTo = '',
        array $extensions = [],
        ?bool $multiple = null,
        ?int $maxSize = 2048,
        null|float|array $resize = null,
        ?array $resizes = null,
        ?int $compress = null,
    ): Uploader;
}
