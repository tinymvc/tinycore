<?php

namespace Spark\Utils;

use Throwable;
use Spark\Contracts\Utils\ViteUtilContract;
use Spark\Support\Traits\Macroable;
use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function ltrim;
use function pathinfo;
use function parse_url;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Class Vite
 *
 * Helper class for integrating Vite with a PHP application. Handles configuration,
 * development server checks, and generates script and link tags for JavaScript and CSS assets.
 *
 * @package Spark\Utils
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Vite implements ViteUtilContract
{
    use Macroable;

    /** @var array Configuration array for the Vite helper */
    private array $config;

    private const DEFAULT_ENTRY = 'app.js';
    private const DEFAULT_HOST = 'localhost';
    private const DEFAULT_SCHEME = 'http';
    private const DEFAULT_PORT = 5173;
    private const DEFAULT_DIST_DIR = 'build';
    private const DEFAULT_PUBLIC_DIR = 'public';
    private const DEV_CHECK_TIMEOUT_SECONDS = 2;
    private const STYLE_EXTENSIONS = [
        'css',
        'less',
        'pcss',
        'postcss',
        'sass',
        'scss',
        'styl',
        'stylus',
    ];

    /**
     * Constructor for the Vite helper class.
     *
     * @param string|array $config Configuration options or entry file name.
     */
    public function __construct(string|array $config = [])
    {
        $this->configure($config);
    }

    /**
     * Configures the Vite helper with provided settings.
     *
     * @param string|array $config Configuration options or entry file name.
     */
    public function configure(string|array $config): self
    {
        if (is_string($config)) {
            $config = ['entry' => $config];
        }

        if (is_array($config) && array_is_list($config)) {
            $config = ['entry' => $config];
        }

        $this->config = [
            'scheme' => self::DEFAULT_SCHEME,
            'host' => self::DEFAULT_HOST,
            'port' => self::DEFAULT_PORT,
            'running' => null,
            'base' => '/',
            'root' => null,
            'entry' => self::DEFAULT_ENTRY,
            'dist' => self::DEFAULT_DIST_DIR,
            'dist_path' => self::DEFAULT_PUBLIC_DIR,
            'manifest' => null,
            'manifest_path' => null,
            ...$config
        ];

        if (array_key_exists('running', $config)) {
            $this->config['running'] = $config['running'] === null ? null : (bool) $config['running'];
        } else {
            $this->config['running'] = null;
        }

        $this->config['scheme'] = $this->normalizeScheme((string) $this->config('scheme'));
        $this->config['host'] = $this->normalizeHost((string) $this->config('host'));
        $this->config['port'] = $this->normalizePort($this->config('port'));
        $this->config['base'] = $this->normalizeBasePath((string) $this->config('base'));

        $root = is_string($this->config('root')) ? (string) $this->config('root') : '';
        if ($root !== '' && $this->config('base') === '/') {
            $this->config['base'] = $this->normalizeBasePath($root);
        }

        $this->config['root'] = $this->config('base');
        $this->config['entry'] = $this->normalizeEntries($this->config('entry'));
        $this->config['dist_path'] = $this->normalizePublicPath((string) $this->config('dist_path'));
        $this->config['dist'] = $this->normalizeBuildDirectory((string) $this->config('dist'));

        if ($this->config['entry'] === []) {
            $this->config['entry'] = [self::DEFAULT_ENTRY];
        }

        return $this;
    }

    /**
     * Retrieves a configuration value by key, with an optional default.
     *
     * @param string $key The configuration key.
     * @param mixed $default The default value if the key is not found.
     * @return mixed The configuration value or the default.
     */
    public function config(string $key, $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Generates the full HTML output including JavaScript and CSS tags.
     *
     * @return string The combined HTML string of JavaScript and CSS tags.
     */
    public function __toString(): string
    {
        $entries = $this->entryPoints();
        if ($entries === []) {
            return '';
        }

        $output = '';
        $firstEntry = $entries[0];

        if ($this->isRunning($firstEntry)) {
            $output .= $this->scriptTag($this->serverUrl('@vite/client'));

            if ($this->requiresReactRefresh($entries)) {
                $output .= $this->reactRefreshTag($firstEntry);
            }
        }

        return $output . $this->importModules($entries);
    }

    /**
     * Generates the HTML tags for importing JavaScript and CSS modules.
     *
     * @return string The combined HTML string of JavaScript and CSS tags.
     */
    public function importModules(null|string|array $entry = null): string
    {
        $entries = $this->normalizeEntries($entry ?? $this->config('entry'));
        $seenJs = [];
        $seenPreload = [];
        $seenCss = [];
        $tags = '';

        foreach ($entries as $entrypoint) {
            if ($entrypoint === '') {
                continue;
            }

            $isRunning = $this->isRunning($entrypoint);
            if ($isRunning) {
                $url = $this->serverUrl($entrypoint);

                if ($this->isStylePath($entrypoint)) {
                    if (!isset($seenCss[$url])) {
                        $seenCss[$url] = true;
                        $tags .= $this->stylesheetTag($url);
                    }
                } elseif (!isset($seenJs[$url])) {
                    $seenJs[$url] = true;
                    $tags .= $this->scriptTag($url);
                }

                continue;
            }

            foreach ($this->cssUrls($entrypoint) as $url) {
                if (!isset($seenCss[$url])) {
                    $seenCss[$url] = true;
                    $tags .= $this->stylesheetTag($url);
                }
            }

            $url = $this->assetUrl($entrypoint);
            if ($url !== '' && $this->isStylePath($url)) {
                if (!isset($seenCss[$url])) {
                    $seenCss[$url] = true;
                    $tags .= $this->stylesheetTag($url);
                }
            } elseif ($url !== '' && !isset($seenJs[$url])) {
                $seenJs[$url] = true;
                $tags .= $this->scriptTag($url);
            }

            foreach ($this->importsUrls($entrypoint) as $url) {
                if (!isset($seenPreload[$url])) {
                    $seenPreload[$url] = true;
                    $tags .= $this->modulePreloadTag($url);
                }
            }
        }

        return $tags;
    }

    /**
     * Generates a script tag to inject React Refresh runtime.
     *
     * @param null|string $entry The entry file name.
     * @return string The HTML script tag for React Refresh runtime.
     */
    public function reactRefreshTag(?string $entry = null): string
    {
        $entry = $this->normalizeEntries($entry)[0] ?? '';
        $tag = '';

        if ($entry !== '' && $this->isRunning($entry)) {
            $refreshUrl = json_encode(
                $this->serverUrl('@react-refresh'),
                JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_UNESCAPED_SLASHES
            );

            $tag = <<<HTML
                <script type="module">
                    import RefreshRuntime from {$refreshUrl};
                    RefreshRuntime.injectIntoGlobalHook(window);
                    window.\$RefreshReg$ = () => { };
                    window.\$RefreshSig$ = () => (type) => type;
                    window.__vite_plugin_react_preamble_installed__ = true;
                </script>
            HTML;
        }

        return $tag;
    }

    /**
     * Checks if the Vite development server is running.
     *
     * @param string $entry The entry file name to check.
     * @return bool True if the server is running, false otherwise.
     */
    public function isRunning(string $entry): bool
    {
        $isRunning = $this->config('running');
        if ($isRunning !== null) {
            return $isRunning;
        }

        if ($this->hasManifest()) {
            return $this->config['running'] = false;
        }

        try {
            return $this->config['running'] = http(
                'GET',
                $this->serverUrl('@vite/client'),
                [],
                [],
                [CURLOPT_TIMEOUT => self::DEV_CHECK_TIMEOUT_SECONDS, CURLOPT_CONNECTTIMEOUT => 1]
            )->ok();
        } catch (Throwable) {
            return $this->config['running'] = false;
        }
    }

    /**
     * Generates a JavaScript tag for the given entry file.
     *
     * @param string $entry The entry file name.
     * @return string The HTML script tag for the JavaScript file.
     */
    public function jsTag(string $entry): string
    {
        $entry = $this->normalizeEntries($entry)[0] ?? '';
        if ($entry === '') {
            return '';
        }

        $url = $this->isRunning($entry) ? $this->serverUrl($entry) : $this->assetUrl($entry);

        return $url !== '' ? $this->scriptTag($url) : '';
    }

    /**
     * Generates HTML link tags to preload JavaScript imports for the given entry file.
     *
     * @param string $entry The entry file name.
     * @return string The HTML link tags for preloading JavaScript imports.
     */
    public function jsPreloadImports(string $entry): string
    {
        if ($this->isRunning($entry)) {
            return '';
        }

        return array_reduce(
            $this->importsUrls($entry),
            fn($res, $url) => $res . $this->modulePreloadTag($url),
            ''
        );
    }

    /**
     * Generates a CSS tag for the given entry file.
     *
     * @param string $entry The entry file name.
     * @return string The HTML link tag for the CSS file.
     */
    public function cssTag(string $entry): string
    {
        if ($this->isRunning($entry)) {
            return $this->isStylePath($entry)
                ? $this->stylesheetTag($this->serverUrl($entry))
                : '';
        }

        return array_reduce(
            $this->cssUrls($entry),
            fn($tags, $url) => $tags . $this->stylesheetTag($url),
            ''
        );
    }

    /**
     * Retrieves the Vite manifest file as an associative array.
     *
     * @return array The manifest data from the Vite build.
     */
    public function getManifest(): array
    {
        if (is_array($this->config['manifest'])) {
            return $this->config['manifest'];
        }

        if (!$this->hasManifest()) {
            return $this->config['manifest'] = [];
        }

        $contents = @file_get_contents($this->getManifestPath());
        if ($contents === false) {
            return $this->config['manifest'] = [];
        }

        $manifest = json_decode($contents, true);
        return $this->config['manifest'] = is_array($manifest) ? $manifest : [];
    }

    /**
     * Checks if the Vite manifest exists.
     *
     * @return bool
     *   TRUE if the manifest file exists, FALSE otherwise.
     */
    public function hasManifest(): bool
    {
        return is_file($this->getManifestPath());
    }

    /**
     * Retrieves the path to the Vite manifest file.
     *
     * @return string
     *   The path to the Vite manifest file.
     */
    public function getManifestPath(): string
    {
        $manifestPath = $this->config('manifest_path');
        if (is_string($manifestPath) && $manifestPath !== '') {
            return str_starts_with($manifestPath, '/')
                ? $manifestPath
                : root_dir($manifestPath);
        }

        return root_dir($this->buildPath() . '/.vite/manifest.json');
    }

    /**
     * Gets the asset URL for the given entry file based on the Vite manifest.
     *
     * @param string $entry The entry file name.
     * @return string The URL for the asset.
     */
    public function assetUrl(string $entry): string
    {
        $entry = $this->normalizeEntries($entry)[0] ?? '';
        if ($entry === '') {
            return '';
        }

        $manifest = $this->getManifest();
        $chunk = $manifest[$entry] ?? null;
        $file = is_array($chunk) ? ($chunk['file'] ?? null) : null;

        return is_string($file) && $file !== '' ? $this->distUrl($file) : '';
    }

    /**
     * Retrieves the URLs for JavaScript imports associated with the given entry file.
     *
     * @param string $entry The entry file name.
     * @return array The array of URLs for JavaScript imports.
     */
    public function importsUrls(string $entry): array
    {
        $entry = $this->normalizeEntries($entry)[0] ?? '';
        if ($entry === '') {
            return [];
        }

        $manifest = $this->getManifest();
        $urls = [];
        $seenFiles = [];
        $seenChunks = [];
        $chunk = $manifest[$entry] ?? null;
        $entryFile = is_array($chunk) ? ($chunk['file'] ?? null) : null;
        if (is_string($entryFile) && $entryFile !== '') {
            $seenFiles[$entryFile] = true;
        }

        $this->collectImportsUrls($entry, $manifest, $urls, $seenFiles, $seenChunks);

        return $urls;
    }

    /**
     * Returns the URL for the given asset, taking into account if the Vite
     * development server is running or not.
     *
     * If the development server is running, the URL for the asset on the server
     * is returned. Otherwise, the URL for the asset in the build directory is
     * returned.
     *
     * @param string $entry The asset file name.
     * @return string The URL for the asset.
     */
    public function asset(string $entry): string
    {
        return $this->isRunning($entry) ? $this->serverUrl($entry) : $this->assetUrl($entry);
    }

    /**
     * Retrieves the URLs for CSS files associated with the given entry file.
     *
     * @param string $entry The entry file name.
     * @return array The array of URLs for CSS files.
     */
    public function cssUrls(string $entry): array
    {
        $entry = $this->normalizeEntries($entry)[0] ?? '';
        if ($entry === '') {
            return [];
        }

        $manifest = $this->getManifest();
        $urls = [];
        $seenFiles = [];
        $seenChunks = [];

        $this->collectCssUrls($entry, $manifest, $urls, $seenFiles, $seenChunks);

        return $urls;
    }

    /**
     * Constructs the URL for the Vite development server.
     *
     * @param string $path Optional path to append to the server URL.
     * @return string The full URL for the Vite development server.
     */
    private function serverUrl(string $path = ''): string
    {
        $base = sprintf(
            '%s://%s:%d%s',
            $this->config('scheme'),
            $this->config('host'),
            (int) $this->config('port'),
            $this->config('base')
        );

        return $path === ''
            ? rtrim($base, '/')
            : rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Constructs the URL for the asset in the distribution directory.
     *
     * @param string $path Optional path to append to the distribution URL.
     * @return string The full URL for the asset.
     */
    private function distUrl(string $path = ''): string
    {
        $filePath = ltrim($path, '/');
        if ($filePath === '') {
            return '';
        }

        return home_url($this->config('dist') . '/' . $filePath);
    }

    /**
     * Builds normalized build path.
     *
     * @return string
     */
    private function buildPath(): string
    {
        $public = rtrim($this->config('dist_path'), '/');
        $dist = rtrim($this->config('dist'), '/');

        return $public === '' ? $dist : $public . '/' . $dist;
    }

    /**
     * Collects all nested manifest imports recursively.
     *
     * @param string $entry
     * @param array $manifest
     * @param array $urls
     * @param array $seenFiles
     * @param array $seenChunks
     * @return void
     */
    private function collectImportsUrls(
        string $entry,
        array $manifest,
        array &$urls,
        array &$seenFiles,
        array &$seenChunks
    ): void {
        if (isset($seenChunks[$entry])) {
            return;
        }

        $seenChunks[$entry] = true;
        $chunk = $manifest[$entry] ?? null;
        if (!is_array($chunk) || !isset($chunk['imports']) || !is_array($chunk['imports'])) {
            return;
        }

        foreach ($chunk['imports'] as $import) {
            if (!is_string($import)) {
                continue;
            }

            $importedChunk = $manifest[$import] ?? null;
            if (!is_array($importedChunk)) {
                continue;
            }

            $this->collectImportsUrls($import, $manifest, $urls, $seenFiles, $seenChunks);

            $file = $importedChunk['file'] ?? null;
            if (!is_string($file) || $file === '' || $this->isStylePath($file) || isset($seenFiles[$file])) {
                continue;
            }

            $seenFiles[$file] = true;
            $urls[] = $this->distUrl($file);
        }
    }

    /**
     * Collects CSS URLs for an entry and all of its nested imports.
     *
     * @param string $entry
     * @param array $manifest
     * @param array $urls
     * @param array $seenFiles
     * @param array $seenChunks
     * @return void
     */
    private function collectCssUrls(
        string $entry,
        array $manifest,
        array &$urls,
        array &$seenFiles,
        array &$seenChunks
    ): void {
        if (isset($seenChunks[$entry])) {
            return;
        }

        $seenChunks[$entry] = true;
        $chunk = $manifest[$entry] ?? null;
        if (!is_array($chunk)) {
            return;
        }

        if (isset($chunk['css']) && is_array($chunk['css'])) {
            foreach ($chunk['css'] as $file) {
                if (!is_string($file) || $file === '' || isset($seenFiles[$file])) {
                    continue;
                }

                $seenFiles[$file] = true;
                $urls[] = $this->distUrl($file);
            }
        }

        if (isset($chunk['imports']) && is_array($chunk['imports'])) {
            foreach ($chunk['imports'] as $import) {
                if (is_string($import)) {
                    $this->collectCssUrls($import, $manifest, $urls, $seenFiles, $seenChunks);
                }
            }
        }

        $file = $chunk['file'] ?? null;
        if (is_string($file) && $file !== '' && $this->isStylePath($file) && !isset($seenFiles[$file])) {
            $seenFiles[$file] = true;
            $urls[] = $this->distUrl($file);
        }
    }

    /**
     * Normalize entry points.
     *
     * @param mixed $entries
     * @return array
     */
    private function normalizeEntries(mixed $entries): array
    {
        if (is_string($entries)) {
            return $entries !== '' ? [$this->normalizeEntry($entries)] : [];
        }

        if (!is_array($entries)) {
            return [];
        }

        if (!array_is_list($entries)) {
            $value = $entries['entry'] ?? null;
            return $this->normalizeEntries($value);
        }

        $normalized = array_map(fn($entry) => is_string($entry)
            ? $this->normalizeEntry($entry)
            : null, $entries);

        return array_values(array_unique(array_filter($normalized, fn($entry) => $entry !== null && $entry !== '')));
    }

    /**
     * Returns all unique entry points for current configuration.
     *
     * @return array
     */
    private function entryPoints(): array
    {
        return $this->normalizeEntries($this->config('entry'));
    }

    /**
     * Normalize a single entry path.
     *
     * @param string $entry
     * @return string
     */
    private function normalizeEntry(string $entry): string
    {
        return ltrim($entry, '/');
    }

    /**
     * Check if entries include React files.
     *
     * @param array $entries
     * @return bool
     */
    private function requiresReactRefresh(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (in_array($this->pathExtension($entry), ['jsx', 'tsx'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize scheme value.
     *
     * @param string $scheme
     * @return string
     */
    private function normalizeScheme(string $scheme): string
    {
        $scheme = strtolower(trim($scheme));
        $scheme = str_contains($scheme, '://') ? explode('://', $scheme)[0] : $scheme;

        return in_array($scheme, ['http', 'https'], true) ? $scheme : self::DEFAULT_SCHEME;
    }

    /**
     * Normalize host value.
     *
     * @param string $host
     * @return string
     */
    private function normalizeHost(string $host): string
    {
        $host = trim($host);

        return $host === '' ? self::DEFAULT_HOST : $host;
    }

    /**
     * Normalize port value.
     *
     * @param mixed $port
     * @return int
     */
    private function normalizePort(mixed $port): int
    {
        $port = filter_var($port, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        return $port === false ? self::DEFAULT_PORT : $port;
    }

    /**
     * Normalize base path.
     *
     * @param string $path
     * @return string
     */
    private function normalizeBasePath(string $path): string
    {
        return '/' . trim($path, '/');
    }

    /**
     * Normalize public directory path.
     *
     * @param string $path
     * @return string
     */
    private function normalizePublicPath(string $path): string
    {
        return trim($path, '/');
    }

    /**
     * Normalize dist directory path.
     *
     * @param string $path
     * @return string
     */
    private function normalizeBuildDirectory(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? self::DEFAULT_DIST_DIR : $path;
    }

    /**
     * Determine whether a path points to a stylesheet supported by Vite.
     *
     * @param string $path
     * @return bool
     */
    private function isStylePath(string $path): bool
    {
        return in_array($this->pathExtension($path), self::STYLE_EXTENSIONS, true);
    }

    /**
     * Get a lowercase extension from a path or URL.
     *
     * @param string $path
     * @return string
     */
    private function pathExtension(string $path): string
    {
        $urlPath = parse_url($path, PHP_URL_PATH);

        return strtolower(pathinfo(is_string($urlPath) ? $urlPath : $path, PATHINFO_EXTENSION));
    }

    /**
     * Generate a module script tag.
     *
     * @param string $url
     * @return string
     */
    private function scriptTag(string $url): string
    {
        return sprintf(
            '<script type="module" crossorigin src="%s"></script>',
            $this->escapeAttribute($url)
        );
    }

    /**
     * Generate a stylesheet link tag.
     *
     * @param string $url
     * @return string
     */
    private function stylesheetTag(string $url): string
    {
        return sprintf('<link rel="stylesheet" href="%s">', $this->escapeAttribute($url));
    }

    /**
     * Generate a module preload link tag.
     *
     * @param string $url
     * @return string
     */
    private function modulePreloadTag(string $url): string
    {
        return sprintf('<link rel="modulepreload" href="%s">', $this->escapeAttribute($url));
    }

    /**
     * Escape an HTML attribute value.
     *
     * @param string $value
     * @return string
     */
    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
