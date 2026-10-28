<?php

namespace Spark;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Spark\Utils\File;
use function array_key_exists;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function is_dir;
use function is_file;
use function dirname;
use function filemtime;
use function var_export;
use function rtrim;
use function strlen;
use function ltrim;

class Configuration
{
    /**
     * Discover configuration files and cache merged config.
     *
     * @param string $folder Path to configuration directory.
     * @param string $cache Path to cached config file.
     * @param string $env Path to environment file.
     * @param bool $useCache Whether compiled config may be read or written.
     * @return array<string, mixed>
     */
    public static function discover(string $folder, string $cache, string $env = '', bool $useCache = true): array
    {
        $folder = dir_path($folder);
        $cache = dir_path($cache);
        $env = dir_path($env);

        if ($useCache && self::isConfigCacheFresh($folder, $cache, $env)) {
            $cached = self::loadConfigCache($cache);

            if (is_array($cached)) {
                return $cached;
            }
        }

        if (!is_dir($folder)) {
            if ($useCache && is_file($cache)) {
                unlink($cache);
            }

            return [];
        }

        $config = [];
        $files = self::collectConfigFiles($folder);

        foreach ($files as $path => $mtime) {
            $relativePath = substr($path, strlen($folder) + 1);
            $key = str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['.', ''],
                $relativePath
            );

            $value = require $path;

            if (is_array($value)) {
                data_set($config, ltrim($key, '.'), $value);
            }
        }

        if ($useCache) {
            self::writeConfigCache($cache, $config, $files, $env);
        }

        return $config;
    }

    /**
     * Collect file mtimes for config php files to detect source changes.
     *
     * @param string $folder
     * @return array<string, int>
     */
    protected static function collectConfigFiles(string $folder): array
    {
        if (!is_dir($folder)) {
            return [];
        }

        $folder = rtrim($folder, '/\\');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $mtime = $file->getMTime();
            if ($mtime === false) {
                continue;
            }

            $files[$file->getPathname()] = $mtime;
        }

        return $files;
    }

    /**
     * Check whether config cache is fresh based on source file mtimes.
     */
    protected static function isConfigCacheFresh(string $folder, string $cache, string $env): bool
    {
        if (!is_file($cache) || !is_dir($folder)) {
            return false;
        }

        $compiled = self::loadConfigCachePayload($cache);

        if (!is_array($compiled) || !isset($compiled['files']) || !is_array($compiled['files'])) {
            return false;
        }

        $compiledFiles = $compiled['files'];
        $files = self::collectConfigFiles($folder);
        $cacheModifiedAt = filemtime($cache);

        if (count($compiledFiles) !== count($files) || $cacheModifiedAt === false) {
            return false;
        }

        if (!array_key_exists('env', $compiled) || self::configEnvSignature($env) !== $compiled['env']) {
            return false;
        }

        $compiledPaths = array_keys($compiledFiles);
        $currentPaths = array_keys($files);

        sort($compiledPaths);
        sort($currentPaths);

        if ($compiledPaths !== $currentPaths) {
            return false;
        }

        foreach ($files as $path => $mtime) {
            if (!array_key_exists($path, $compiledFiles)) {
                return false;
            }

            $compiledMtime = $compiledFiles[$path];

            if (
                !is_int($compiledMtime) && !(
                    is_string($compiledMtime) && preg_match('/^\d+$/', $compiledMtime) === 1
                )
            ) {
                return false;
            }

            $compiledMtime = (int) $compiledMtime;

            if ($compiledMtime !== $mtime || $mtime > $cacheModifiedAt) {
                return false;
            }
        }

        return true;
    }

    /**
     * Load compiled config cache.
     */
    protected static function loadConfigCache(string $cache): ?array
    {
        $compiled = self::loadConfigCachePayload($cache);

        if (!is_array($compiled)) {
            return null;
        }

        if (isset($compiled['config']) && is_array($compiled['config'])) {
            return $compiled['config'];
        }

        return $compiled;
    }

    /**
     * Load the raw compiled config cache payload.
     */
    protected static function loadConfigCachePayload(string $cache): ?array
    {
        if (!is_file($cache)) {
            return null;
        }

        $config = require $cache;
        return is_array($config) ? $config : null;
    }

    /**
     * Write compiled config cache to disk.
     */
    protected static function writeConfigCache(string $cache, array $config, array $files = [], string $env = ''): void
    {
        $cacheDir = dirname($cache);
        $payload = [
            'config' => $config,
            'files' => $files,
            'env' => self::configEnvSignature($env),
            'generated_at' => time(),
        ];

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return;
        }

        File::putAtomic($cache, '<?php return ' . var_export($payload, true) . ';' . PHP_EOL);
    }

    /**
     * Build the .env signature used to invalidate compiled config.
     */
    protected static function configEnvSignature(string $env): ?array
    {
        if ($env === '' || !is_file($env)) {
            return null;
        }

        $modifiedAt = filemtime($env);

        if ($modifiedAt === false) {
            return null;
        }

        return [
            'path' => $env,
            'mtime' => $modifiedAt,
        ];
    }
}