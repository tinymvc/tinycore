<?php

namespace Spark\Utils;

use Closure;
use DateTimeInterface;
use Spark\Facades\Blade;
use Spark\Foundation\Application;
use Throwable;
use function count;
use function get_class;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Class Highlight
 *
 * This class provides methods to format, syntax-highlight, and dump PHP variables
 * and files with a professional developer experience.
 */
class Highlight
{
    /** @var array Tracks seen objects to prevent infinite recursion during object dumping. */
    private static array $seenObjects = [];

    /**
     * Formats the given PHP variable(s) to syntax-highlighted HTML.
     *
     * @param mixed ...$args The variable(s) to be highlighted.
     * @return string The highlighted output as a string.
     */
    public static function variables(...$args): string
    {
        $html = '';
        foreach ($args as $arg) {
            $html .= '<div class="spark-dump-entry">' . self::valueToHtml($arg) . '</div>';
        }
        return $html;
    }

    /**
     * Dumps the given PHP variable(s) with an interactive, professional UI.
     *
     * @param mixed ...$args The variable(s) to be dumped.
     * @return void
     */
    public static function dump(...$args): void
    {
        $trace = self::resolveCallerTrace();
        $file = $trace['file'] ?? '';
        $line = $trace['line'] ?? 0;
        $relFile = function_exists('root_dir') ? str_replace(root_dir(), '@', $file) : $file;

        if (is_cli()) {
            self::renderCliDump($args, $relFile, $line);
            return;
        }

        echo self::renderHtmlDump($args, $relFile, $file, $line, false);
    }

    /**
     * Dumps the given PHP variable(s) and terminates script execution.
     *
     * @param mixed ...$args The variable(s) to be dumped.
     * @return never
     */
    public static function dumpAndDie(...$args): never
    {
        $trace = self::resolveCallerTrace();
        $file = $trace['file'] ?? '';
        $line = $trace['line'] ?? 0;
        $relFile = function_exists('root_dir') ? str_replace(root_dir(), '@', $file) : $file;

        if (is_cli()) {
            self::renderCliDump($args, $relFile, $line);
            exit(1);
        }

        echo self::renderHtmlDump($args, $relFile, $file, $line, true);
        exit(1);
    }

    /**
     * Recursively formats any PHP value into semantic, collapsible HTML nodes.
     *
     * @param mixed  $value
     * @param int    $depth
     * @param int    $maxDepth
     * @param string $label Pre-rendered key/property label placed on the same line as the value.
     * @return string
     */
    public static function valueToHtml(mixed $value, int $depth = 0, int $maxDepth = 8, string $label = ''): string
    {
        if ($depth === 0) {
            self::$seenObjects = [];
        }

        if ($depth >= $maxDepth) {
            return $label . '<span class="sf-meta">[max depth reached]</span>';
        }

        if ($value === null) {
            return $label . '<span class="sf-null">null</span>';
        }

        if (is_bool($value)) {
            return $label . '<span class="sf-bool">' . ($value ? 'true' : 'false') . '</span>';
        }

        if (is_int($value) || is_float($value)) {
            return $label . '<span class="sf-num">' . $value . '</span>';
        }

        if (is_string($value)) {
            $len = strlen($value);
            $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if (str_contains($value, "\n")) {
                return self::details(
                    $depth,
                    $label . '<span class="sf-type">string:' . $len . '</span> <span class="sf-punct">"""</span>',
                    '<pre class="sf-pre">' . $escaped . '</pre>',
                    '"""'
                );
            }

            return $label . '<span class="sf-str">"' . $escaped . '"</span>'
                . ($len > 30 ? ' <span class="sf-meta">' . $len . '</span>' : '');
        }

        if (is_array($value)) {
            $count = count($value);

            if ($count === 0) {
                return $label . '<span class="sf-type">array</span><span class="sf-punct">[]</span>';
            }

            $children = '';
            foreach ($value as $key => $item) {
                $keyHtml = is_string($key) ? '"' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"' : $key;
                $itemLabel = '<span class="sf-key">' . $keyHtml . '</span><span class="sf-arrow">=&gt;</span>';

                $children .= '<div class="sf-item">' . self::valueToHtml($item, $depth + 1, $maxDepth, $itemLabel) . '</div>';
            }

            return self::details(
                $depth,
                $label . '<span class="sf-type">array:' . $count . '</span> <span class="sf-punct">[</span>',
                '<div class="sf-children">' . $children . '</div>',
                ']'
            );
        }

        if (is_object($value)) {
            $oid = spl_object_id($value);
            $className = htmlspecialchars(get_class($value));

            if ($value instanceof Closure) {
                return $label . '<span class="sf-class">Closure</span> <span class="sf-punct">{…}</span>';
            }

            if ($value instanceof DateTimeInterface) {
                return $label . '<span class="sf-class">' . $className . '</span> <span class="sf-str">"' . $value->format('Y-m-d H:i:s.u P') . '"</span>';
            }

            if (isset(self::$seenObjects[$oid])) {
                return $label . '<span class="sf-class">' . $className . '</span> <span class="sf-meta">#' . $oid . ' ↑ seen above</span>';
            }
            self::$seenObjects[$oid] = true;

            $children = '';
            foreach ((array) $value as $key => $item) {
                $children .= '<div class="sf-item">'
                    . self::valueToHtml($item, $depth + 1, $maxDepth, self::propertyLabel((string) $key))
                    . '</div>';
            }

            return self::details(
                $depth,
                $label . '<span class="sf-class">' . $className . '</span> <span class="sf-meta">#' . $oid . '</span> <span class="sf-punct">{</span>',
                '<div class="sf-children">' . $children . '</div>',
                '}'
            );
        }

        if (is_resource($value)) {
            return $label . '<span class="sf-type">resource</span> <span class="sf-meta">(' . get_resource_type($value) . ')</span>';
        }

        return $label . htmlspecialchars(var_export($value, true));
    }

    /**
     * Wraps a summary and body in a collapsible node (open by default for the first two levels).
     */
    private static function details(int $depth, string $summary, string $body, string $close): string
    {
        $open = $depth < 2 ? ' open' : '';

        return '<details class="sf-details"' . $open . '>'
            . '<summary class="sf-summary">' . $summary . '</summary>'
            . $body
            . '<span class="sf-punct">' . $close . '</span>'
            . '</details>';
    }

    /**
     * Builds the label for an object property from its (possibly mangled) array-cast key.
     *
     * Mangled keys: "\0*\0name" (protected), "\0Owner\\Class\0name" (private), "name" (public).
     */
    private static function propertyLabel(string $rawKey): string
    {
        if ($rawKey === '' || $rawKey[0] !== "\0") {
            $name = $rawKey;
            $symbol = '+';
            $title = 'public';
        } else {
            [, $scope, $name] = explode("\0", $rawKey, 3);
            $isProtected = $scope === '*';
            $symbol = $isProtected ? '#' : '-';
            $title = $isProtected ? 'protected' : 'private (' . $scope . ')';
        }

        return '<span class="sf-vis" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . $symbol . '</span>'
            . '<span class="sf-prop">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span class="sf-arrow">:</span>';
    }

    /**
     * Resolves the caller trace outside Highlight and helpers.
     *
     * @return array
     */
    public static function resolveCallerTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($trace as $frame) {
            $f = $frame['file'] ?? '';
            if ($f && !str_contains($f, 'Highlight.php') && !str_contains($f, 'helpers.php')) {
                return $frame;
            }
        }
        return $trace[1] ?? [];
    }

    /**
     * Renders dump in terminal / CLI context.
     *
     * @param array $args
     * @param string $file
     * @param int $line
     * @return void
     */
    public static function renderCliDump(array $args, string $file, int $line): void
    {
        if ($file) {
            echo "\033[36m// Dumped from: $file:$line\033[0m\n";
        }
        foreach ($args as $arg) {
            var_dump($arg);
        }
    }

    /**
     * Renders HTML dump, using Blade if available or fallback.
     *
     * @param array $args
     * @param string $relFile
     * @param string $rawFile
     * @param int $line
     * @param bool $isDd
     * @return string
     */
    public static function renderHtmlDump(array $args, string $relFile, string $rawFile, int $line, bool $isDd): string
    {
        try {
            if (isset(Application::$app)) {
                Blade::setUsePath(dirname(__DIR__) . '/Foundation/resources/views', 'spark');

                return Blade::render('spark::dump', [
                    'args' => $args,
                    'file' => $relFile,
                    'rawFile' => $rawFile,
                    'line' => $line,
                    'isDd' => $isDd,
                ]);
            }
        } catch (Throwable) {
            // Fall through to standalone HTML renderer
        }

        ob_start();
        var_dump(...$args);

        $output = highlight_string('<?php ' . ob_get_clean(), true);
        $output = str_replace('&lt;?php ', '', $output);

        $dumpHtml = '<pre style="font-size: 16px;margin: 25px;"><code>' . "$output</code></pre>";

        return '<p style="color:#666;font-size: 14px;margin: 25px 25px 0 25px;font-style:italic;"><strong>// Dumped from:</strong> '
            . htmlspecialchars($relFile, ENT_QUOTES, 'UTF-8') . ':' . $line . '</p>' . $dumpHtml;
    }

    /**
     * Highlights the content of a PHP file and returns the highlighted output as a string.
     *
     * @param string $file The path to the PHP file to be highlighted.
     * @param int $line The line number to highlight.
     * @param int $total The total number of lines to display around the specified line number.
     * @return string The highlighted output as a string.
     */
    public static function file(string $file, int $line, int $total = 50): string
    {
        if (!file_exists($file) || !is_file($file)) {
            return '<div class="syntax-highlight-empty">File not found: ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $raw = @highlight_file($file, true);

        if (!$raw) {
            return '<div class="syntax-highlight-empty">This file is empty: ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $raw = preg_replace('/^<pre><code[^>]*>/i', '', $raw);
        $raw = preg_replace('/<\/code><\/pre>$/i', '', $raw);
        $raw = str_replace(["\r\n", "\r", '<br />', '<br>'], "\n", $raw);
        $lines = explode("\n", $raw);
        $lineCount = count($lines);

        $startLine = max(1, (int) ceil($line - ($total / 2)));
        $endLine = min($lineCount, $startLine + $total - 1);
        if ($endLine - $startLine < $total - 1 && $startLine > 1) {
            $startLine = max(1, $endLine - $total + 1);
        }

        $colorMap = [
            '#0000BB' => 'var(--hl-var, #93c5fd)',
            '#007700' => 'var(--hl-key, #f472b6)',
            '#DD0000' => 'var(--hl-str, #a3e635)',
            '#FF8000' => 'var(--hl-com, #94a3b8)',
            '#000000' => 'var(--hl-def, #e2e8f0)',
        ];

        $tagStack = [];
        $output = [];

        foreach ($lines as $idx => $lineHtml) {
            $currentLine = $idx + 1;
            $prefix = implode('', $tagStack);

            preg_match_all('/(<span[^>]*>|<\/span>)/i', $lineHtml, $matches);
            foreach ($matches[0] as $tag) {
                if (str_starts_with($tag, '</')) {
                    array_pop($tagStack);
                } else {
                    $tagStack[] = $tag;
                }
            }

            if ($currentLine >= $startLine && $currentLine <= $endLine) {
                $suffix = str_repeat('</span>', count($tagStack));
                $content = "$prefix$lineHtml$suffix";
                $content = str_replace(array_keys($colorMap), array_values($colorMap), $content);

                $activeClass = ($currentLine === $line) ? ' active' : '';

                $output[] = sprintf(
                    '<div class="syntax-highlight-line%s" data-line="%d"><code><span class="syntax-highlight-no">%d</span><div class="syntax-highlight-code">%s</div></code></div>',
                    $activeClass,
                    $currentLine,
                    $currentLine,
                    $content
                );
            }
        }

        return implode("\n", $output);
    }
}
