<?php

namespace Spark\Foundation\Console;

use FilesystemIterator;
use ReflectionClass;
use RuntimeException;
use Spark\Console\Prompt;
use Spark\Contracts\ServiceProviderContract;
use Spark\Foundation\Application;
use Throwable;
use function array_filter;
use function array_keys;
use function array_replace;
use function array_values;
use function bin2hex;
use function class_exists;
use function copy;
use function dirname;
use function is_dir;
use function is_file;
use function is_link;
use function ltrim;
use function method_exists;
use function mkdir;
use function preg_replace;
use function random_bytes;
use function realpath;
use function rename;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcasecmp;
use function strtolower;
use function trim;
use function unlink;

/** Package resources declared by the providers booted in this application. */
final class VendorPublisher
{
    /** @var array<class-string, array<string, array<string, string>>> */
    private array $groups = [];

    /**
     * @param array<string, string> $paths Absolute source paths mapped to application destinations.
     * @param array<string>|string|null $groups One or more tags, or null for untagged package resources.
     */
    public function register(string $provider, array|string|null $groups, array $paths): void
    {
        $normalizedProvider = $this->normalizeProvider($provider);
        $tags = $groups !== null ? (array) $groups : [null];

        foreach ($tags as $tag) {
            $normalizedTag = $tag !== null ? trim($tag, " \t\n\r\0\x0B\"'") : '';
            $this->groups[$normalizedProvider][$normalizedTag] = array_replace(
                $this->groups[$normalizedProvider][$normalizedTag] ?? [],
                $paths
            );
        }
    }

    /** @return array<class-string, list<string>> */
    public function groups(): array
    {
        $result = [];
        foreach ($this->groups as $provider => $tags) {
            $result[$provider] = array_values(array_filter(array_keys($tags), fn($tag) => $tag !== ''));
        }

        return $result;
    }

    public function hasProvider(string $provider): bool
    {
        $target = $this->normalizeProvider($provider);
        foreach (array_keys($this->groups) as $class) {
            if ($this->matchesProvider($class, $target)) {
                return true;
            }
        }

        return false;
    }

    public function publish(?string $provider = null, ?string $tag = null, bool $force = false): void
    {
        $targetProvider = $provider !== null ? $this->normalizeProvider($provider) : null;
        $targetTag = $tag !== null ? trim($tag, " \t\n\r\0\x0B\"'") : null;

        if ($targetProvider !== null && !$this->hasProvider($targetProvider)) {
            $this->bootProviderIfPossible($targetProvider);
        }

        $matched = false;
        foreach ($this->groups as $class => $groups) {
            if ($targetProvider !== null && !$this->matchesProvider($class, $targetProvider)) {
                continue;
            }
            foreach ($groups as $name => $paths) {
                // When a tag is specified, only match groups with that tag (skip untagged resources)
                if ($targetTag !== null && $targetTag !== $name) {
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

    public function normalizeProvider(string $provider): string
    {
        $trimmed = trim($provider, " \t\n\r\0\x0B\"'");
        $slashed = str_replace('/', '\\', $trimmed);
        $collapsed = preg_replace('#\\\\+#', '\\', $slashed) ?? $slashed;

        return ltrim($collapsed, '\\');
    }

    public function matchesProvider(string $registeredClass, string $targetProvider): bool
    {
        $registered = $this->normalizeProvider($registeredClass);
        $target = $this->normalizeProvider($targetProvider);

        if (strcasecmp($registered, $target) === 0) {
            return true;
        }

        if (str_ends_with(strtolower($registered), '\\' . strtolower($target))) {
            return true;
        }

        return false;
    }

    private function bootProviderIfPossible(string $providerClass): void
    {
        $app = Application::$app;
        if ($app === null || !class_exists($providerClass)) {
            return;
        }

        try {
            $ref = new ReflectionClass($providerClass);
            if ($ref->isAbstract() || !$ref->isInstantiable()) {
                return;
            }

            $constructor = $ref->getConstructor();
            $instance = ($constructor && $constructor->getNumberOfRequiredParameters() > 0)
                ? $app->make($providerClass)
                : new $providerClass();

            if ($instance instanceof ServiceProviderContract || method_exists($instance, 'register')) {
                $app->addServiceProvider($instance);
                if (method_exists($instance, 'boot')) {
                    $app->call([$instance, 'boot']);
                }
            }
        } catch (Throwable) {
            // Uninstantiable or failing provider falls through to the normal unmatched exception.
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
