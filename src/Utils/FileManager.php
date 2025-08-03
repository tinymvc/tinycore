<?php

namespace Spark\Utils;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * FileManager class provides utility methods for file and directory operations.
 * 
 * This class includes methods to check file existence, read/write files, manage directories,
 * and perform various file operations such as copying, moving, deleting, and retrieving file information.
 * 
 * This class is designed to be used statically, without the need for instantiation.
 * 
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class FileManager
{
    /**
     * Check if a file exists
     * 
     * @param string $path
     * @return bool
     */
    public static function exists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Check if path is a file
     * 
     * @param string $path
     * @return bool
     */
    public static function isFile(string $path): bool
    {
        return is_file($path);
    }

    /**
     * Check if path is an image file
     * 
     * @param string $path
     * @return bool
     */
    public static function isImage(string $path): bool
    {
        $mimeType = static::mimeType($path);
        return str_starts_with($mimeType, 'image/');
    }

    /**
     * Check if path is a directory
     * 
     * @param string $path
     * @return bool
     */
    public static function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    /**
     * Check if file/directory is readable
     * 
     * @param string $path
     * @return bool
     */
    public static function isReadable(string $path): bool
    {
        return is_readable($path);
    }

    /**
     * Check if file/directory is writable
     * 
     * @param string $path
     * @return bool
     */
    public static function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    /**
     * Get file contents
     * 
     * @param string $path
     * @return string|false
     */
    public static function get(string $path): string|false
    {
        if (!static::exists($path)) {
            return false;
        }
        return file_get_contents($path);
    }

    /**
     * Put contents to a file
     * 
     * @param string $path
     * @param mixed $contents
     * @return int|false
     */
    public static function put(string $path, mixed $contents, int $flags = 0): int|false
    {
        return file_put_contents($path, $contents, $flags);
    }

    /**
     * Prepend content to a file
     * 
     * @param string $path
     * @param string $data
     * @return int|false
     */
    public static function prepend(string $path, string $data): int|false
    {
        if (static::exists($path)) {
            return static::put($path, $data . static::get($path));
        }
        return static::put($path, $data);
    }

    /**
     * Append content to a file
     * 
     * @param string $path
     * @param string $data
     * @return int|false
     */
    public static function append(string $path, string $data): int|false
    {
        return file_put_contents($path, $data, FILE_APPEND | LOCK_EX);
    }

    /**
     * Delete one or more files
     * 
     * @param string|array $paths
     * @return bool
     */
    public static function delete(string|array $paths): bool
    {
        $paths = is_array($paths) ? $paths : func_get_args();
        $success = true;

        foreach ($paths as $path) {
            try {
                if (!static::exists($path)) {
                    continue;
                }

                if (!unlink($path)) {
                    $success = false;
                }
            } catch (\Exception $e) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Move a file to a new location
     * 
     * @param string $from
     * @param string $to
     * @return bool
     */
    public static function move(string $from, string $to): bool
    {
        return rename($from, $to);
    }

    /**
     * Copy a file to a new location
     * 
     * @param string $from
     * @param string $to
     * @return bool
     */
    public static function copy(string $from, string $to): bool
    {
        return copy($from, $to);
    }

    /**
     * Get file size in bytes
     * 
     * @param string $path
     * @return int|false
     */
    public static function size(string $path): int|false
    {
        return filesize($path);
    }

    /**
     * Get file's last modification time
     * 
     * @param string $path
     * @return int|false
     */
    public static function lastModified(string $path): int|false
    {
        return filemtime($path);
    }

    /**
     * Get file extension
     * 
     * @param string $path
     * @return string
     */
    public static function extension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * Get file name without extension
     * 
     * @param string $path
     * @return string
     */
    public static function name(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * Get file basename
     * 
     * @param string $path
     * @return string
     */
    public static function basename(string $path): string
    {
        return pathinfo($path, PATHINFO_BASENAME);
    }

    /**
     * Get directory name
     * 
     * @param string $path
     * @return string
     */
    public static function dirname(string $path): string
    {
        return pathinfo($path, PATHINFO_DIRNAME);
    }

    /**
     * Get file type/mime type
     * 
     * @param string $path
     * @return string|false
     */
    public static function mimeType(string $path): string|false
    {
        return finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
    }

    /**
     * Get file permissions
     * 
     * @param string $path
     * @return string|false
     */
    public static function permissions(string $path): string|false
    {
        if (!static::exists($path)) {
            return false;
        }
        return substr(sprintf('%o', fileperms($path)), -4);
    }

    /**
     * Change file permissions
     * 
     * @param string $path
     * @param int $mode
     */
    public static function chmod(string $path, int $mode): bool
    {
        return chmod($path, $mode);
    }

    /**
     * Create a directory
     * 
     * @param string $path
     * @param int $mode
     * @param bool $recursive
     * @return bool
     */
    public static function makeDirectory(string $path, int $mode = 0755, bool $recursive = false): bool
    {
        if (static::isDirectory($path)) {
            return true;
        }
        return mkdir($path, $mode, $recursive);
    }

    /**
     * Create directory recursively
     * 
     * @param string $path
     * @param int $mode
     * @param bool $recursive
     * @return bool
     */
    public static function ensureDirectoryExists(string $path, int $mode = 0755): bool
    {
        if (!static::isDirectory($path)) {
            return static::makeDirectory($path, $mode, true);
        }
        return true;
    }

    /**
     * Ensure directory is writable
     * 
     * This method checks if the directory exists and is writable.
     * If it does not exist, it attempts to create it with the specified mode.
     * 
     * @param string $path The directory path to check.
     * @param int $mode The permissions to set if the directory needs to be created.
     * @return bool True if the directory is writable, false otherwise.
     */
    public static function ensureDirectoryWritable(string $path, int $mode = 0755): bool
    {
        self::ensureDirectoryExists($path, $mode);

        // Check if directory is writable
        if (self::isWritable($path)) {
            return true;
        }

        // Try to change permissions
        return self::chmod($path, $mode);
    }

    /**
     * Delete a directory
     * 
     * @param string $directory
     * @param bool $preserve Whether to preserve the directory itself after deleting contents
     * @return bool
     */
    public static function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        if (!static::isDirectory($directory)) {
            return false;
        }

        $items = new FilesystemIterator($directory);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                static::deleteDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        if (!$preserve) {
            return rmdir($directory);
        }

        return true;
    }

    /**
     * Clean directory (remove all contents but keep directory)
     * 
     * @param string $directory
     * @return bool
     */
    public static function cleanDirectory(string $directory): bool
    {
        return static::deleteDirectory($directory, true);
    }

    /**
     * Get all files in a directory
     * 
     * @param string $directory
     * @param bool $hidden Whether to include hidden files (starting with dot)
     * @return array
     */
    public static function files(string $directory, bool $hidden = false): array
    {
        if (!static::isDirectory($directory)) {
            return [];
        }

        $files = [];
        $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            if ($file->isFile() && ($hidden || !static::isHidden($file->getFilename()))) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get all files recursively
     * 
     * @param string $directory
     * @param bool $hidden Whether to include hidden files (starting with dot)
     * @return array
     */
    public static function allFiles(string $directory, bool $hidden = false): array
    {
        if (!static::isDirectory($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && ($hidden || !static::isHidden($file->getFilename()))) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get all directories in a path
     * 
     * @param string $directory
     * @return array
     */
    public static function directories(string $directory): array
    {
        if (!static::isDirectory($directory)) {
            return [];
        }

        $directories = [];
        $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $directories[] = $file->getPathname();
            }
        }

        return $directories;
    }

    /**
     * Copy directory recursively
     * 
     * @param string $from
     * @param string $to
     * @param int|null $options
     * @return bool
     */
    public static function copyDirectory(string $from, string $to, ?int $options = null): bool
    {
        if (!static::isDirectory($from)) {
            return false;
        }

        $options = $options ?: FilesystemIterator::SKIP_DOTS;

        if (!static::isDirectory($to)) {
            static::makeDirectory($to, 0755, true);
        }

        $items = new FilesystemIterator($from, $options);

        foreach ($items as $item) {
            $target = $to . '/' . $item->getBasename();

            if ($item->isDir()) {
                static::copyDirectory($item->getPathname(), $target, $options);
            } else {
                static::copy($item->getPathname(), $target);
            }
        }

        return true;
    }

    /**
     * Move directory
     * 
     * @param string $from
     * @param string $to
     * @param bool $overwrite Whether to overwrite existing directory
     * @return bool
     */
    public static function moveDirectory(string $from, string $to, bool $overwrite = false): bool
    {
        if ($overwrite && static::isDirectory($to) && !static::deleteDirectory($to)) {
            return false;
        }

        return rename($from, $to);
    }

    /**
     * Get directory size in bytes
     * 
     * @param string $directory
     * @return int
     */
    public static function directorySize(string $directory): int
    {
        if (!static::isDirectory($directory)) {
            return 0;
        }

        $size = 0;
        $files = static::allFiles($directory);

        foreach ($files as $file) {
            $size += static::size($file);
        }

        return $size;
    }

    /**
     * Check if filename is hidden (starts with dot)
     * 
     * @param string $filename
     * @return bool
     */
    protected static function isHidden(string $filename): bool
    {
        return str_starts_with($filename, '.');
    }

    /**
     * Search for files by pattern
     * 
     * @param string $pattern
     * @param int $flags
     * @return array
     */
    public static function glob(string $pattern, int $flags = 0): array
    {
        return glob($pattern, $flags) ?: [];
    }

    /**
     * Get human readable file size
     * 
     * @param int $bytes
     * @param int $decimals
     * @return string
     */
    public static function humanFileSize(int $bytes, int $decimals = 2): string
    {
        $size = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . @$size[$factor];
    }

    /**
     * Generate unique filename
     * 
     * @param string $directory
     * @param string $filename
     * @return string
     */
    public static function uniqueFilename(string $directory, string $filename): string
    {
        $name = static::name($filename);
        $extension = static::extension($filename);
        $counter = 1;

        $newFilename = $filename;

        while (static::exists($directory . '/' . $newFilename)) {
            $newFilename = $name . '_' . $counter . ($extension ? '.' . $extension : '');
            $counter++;
        }

        return $newFilename;
    }

    /**
     * Write data to file atomically
     * 
     * @param string $path
     * @param mixed $data
     * @return bool
     */
    public static function putAtomic(string $path, mixed $data): bool
    {
        $tempFile = $path . '.tmp.' . uniqid();

        if (static::put($tempFile, $data) === false) {
            return false;
        }

        return rename($tempFile, $path);
    }

    /**
     * Get file hash
     * 
     * @param string $path
     * @param string $algorithm
     * @return string|false
     */
    public static function hash(string $path, string $algorithm = 'md5'): string|false
    {
        if (!static::exists($path)) {
            return false;
        }

        return hash_file($algorithm, $path);
    }

    /**
     * Check if two files are identical
     * 
     * @param string $file1
     * @param string $file2
     * @return bool
     */
    public static function same(string $file1, string $file2): bool
    {
        if (!static::exists($file1) || !static::exists($file2)) {
            return false;
        }

        return static::hash($file1) === static::hash($file2);
    }

    /**
     * Get file lines as array
     * 
     * @param string $path
     * @return array|false
     */
    public static function lines(string $path): array|false
    {
        if (!static::exists($path)) {
            return false;
        }

        return file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }

    /**
     * Replace content in file
     * 
     * @param string $path
     * @param array|string $search
     * @param array|string $replace
     * @return bool
     */
    public static function replace(string $path, array|string $search, array|string $replace): bool
    {
        if (!static::exists($path)) {
            return false;
        }

        $content = static::get($path);
        $newContent = str_replace($search, $replace, $content);

        return static::put($path, $newContent) !== false;
    }

    /**
     * Create a symbolic link
     * 
     * @param string $target
     * @param string $link
     * @return bool
     */
    public static function link(string $target, string $link): bool
    {
        if (windows_os()) {
            // Use junction on Windows
            $cmd = sprintf('mklink /J "%s" "%s"', $link, $target);

            exec($cmd, $output, $retval);

            return $retval === 0; // Check if command was successful
        }

        return symlink($target, $link);
    }

    /**
     * Check if path is a symbolic link
     * 
     * @param string $path
     * @return bool
     */
    public static function isLink(string $path): bool
    {
        return is_link($path);
    }

    /**
     * Get real path (resolves symbolic links)
     * 
     * @param string $path
     * @return string|false
     */
    public static function realPath(string $path): string|false
    {
        return realpath($path);
    }
}
