<?php

namespace Spark\Foundation\Console;

use FilesystemIterator;
use RuntimeException;
use Spark\Console\Prompt;

/** Package resources declared by the providers booted in this application. */
final class VendorPublisher
{
    /** @var array<class-string, array<string, array<string, string>>> */
    private array $groups = [];

    /** @param array<string, string> $paths Absolute source paths mapped to application destinations. */
    public function register(string $provider, string $tag, array $paths): void
    {
        $this->groups[$provider][$tag] = array_replace($this->groups[$provider][$tag] ?? [], $paths);
    }

    /** @return array<class-string, list<string>> */
    public function groups(): array
    {
        return array_map('array_keys', $this->groups);
    }

    public function publish(?string $provider = null, ?string $tag = null, bool $force = false): void
    {
        $matched = false;
        foreach ($this->groups as $class => $groups) {
            if ($provider !== null && $provider !== $class) {
                continue;
            }
            foreach ($groups as $name => $paths) {
                if ($tag !== null && $tag !== $name) {
                    continue;
                }
                $matched = true;
                foreach ($paths as $source => $destination) {
                    $this->copy($source, $destination, $force);
                }
            }
        }

        if (!$matched) {
            throw new RuntimeException('No publishable resources match the selected provider or tag.');
        }
    }

    private function copy(string $source, string $destination, bool $force): void
    {
        if (is_dir($source)) {
            $this->ensureDirectory($destination);
            $from = realpath($source);
            $to = realpath($destination);
            if ($from === $to || str_starts_with($to . DIRECTORY_SEPARATOR, $from . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Cannot publish a directory into itself: $destination");
            }
            foreach (new FilesystemIterator($source) as $item) {
                if (!$item->isLink() || !$item->isDir()) {
                    $this->copy($item->getPathname(), $destination . '/' . $item->getFilename(), $force);
                }
            }
            return;
        }

        if (!is_file($source)) {
            throw new RuntimeException("Publishable file does not exist: $source");
        }
        if (!$force && (is_file($destination) || is_link($destination))) {
            Prompt::info("Keeping $destination");
            return;
        }

        $this->ensureDirectory(dirname($destination));
        $temporary = "$destination.tmp." . bin2hex(random_bytes(12));
        try {
            if (!@copy($source, $temporary)) {
                throw new RuntimeException("Could not copy $source to $destination");
            }
            // Install the complete file. Without --force, a concurrent publisher wins safely.
            if (!($force ? @rename($temporary, $destination) : @link($temporary, $destination))) {
                if (!$force && (is_file($destination) || is_link($destination))) {
                    Prompt::info("Keeping $destination");
                    return;
                }
                throw new RuntimeException("Could not publish $destination");
            }
            Prompt::success("Published $destination");
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create directory: $directory");
        }
    }
}
