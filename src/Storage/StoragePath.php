<?php

namespace Spark\Storage;

use InvalidArgumentException;

/** Disk keys are relative paths, never URLs or paths outside their root. */
final class StoragePath
{
    public static function normalize(string $path, bool $allowEmpty = false): string
    {
        $path = str_replace('\\', '/', $path);

        if (
            str_starts_with($path, '/') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)
            || preg_match('/[\x00-\x1f\x7f]/', $path) || !preg_match('//u', $path)
        ) {
            throw new InvalidArgumentException('Disk paths must be relative, valid UTF-8 paths without control characters.');
        }

        $parts = array_values(array_filter(explode('/', $path), static fn($part) => $part !== ''));
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..' || str_contains($part, ':')) {
                throw new InvalidArgumentException('Disk paths cannot contain traversal segments or drive/stream prefixes.');
            }
        }

        $key = implode('/', $parts);
        if ($key === '' && !$allowEmpty) {
            throw new InvalidArgumentException('A file path is required.');
        }

        return $key;
    }

    public static function encode(string $key): string
    {
        return str_replace('%2F', '/', rawurlencode($key));
    }
}
