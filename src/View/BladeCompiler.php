<?php

namespace Spark\View;

use RuntimeException;
use Spark\Utils\File;
use Spark\View\Compilers\ComponentCompiler;
use Spark\View\Compilers\Concerns\ParsesBladeSyntax;
use Spark\View\Compilers\DirectiveCompiler;
use Spark\View\Compilers\EchoCompiler;
use Spark\View\Compilers\IncludeCompiler;
use Spark\View\Compilers\LayoutCompiler;
use Spark\View\Compilers\RawBlockCompiler;
use Spark\View\Contracts\BladeCompilerContract;
use function bin2hex;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function is_writable;
use function md5;
use function random_bytes;
use function str_contains;
use function str_replace;
use function strtr;
use function unlink;

/**
 * Class BladeCompiler
 * 
 * Compiles Blade-like templates to PHP using modular, on-demand sub-compilers.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class BladeCompiler implements BladeCompilerContract
{
    use ParsesBladeSyntax;

    public const EXPRESSION_DIRECTIVES = RawBlockCompiler::EXPRESSION_DIRECTIVES;

    /**
     * Path to store compiled templates
     */
    private string $cachePath;

    /**
     * Array of custom directives
     */
    private array $customDirectives = [];

    /**
     * Raw PHP blocks to preserve during compilation
     */
    private array $rawBlocks = [];

    /**
     * Literal blocks to preserve during compilation
     */
    private array $literalBlocks = [];

    /**
     * Expression blocks to preserve during compilation
     */
    private array $expressionBlocks = [];

    /**
     * Lazy-loaded sub-compilers
     */
    private ?RawBlockCompiler $rawBlockCompiler = null;
    private ?ComponentCompiler $componentCompiler = null;
    private ?EchoCompiler $echoCompiler = null;
    private ?LayoutCompiler $layoutCompiler = null;
    private ?IncludeCompiler $includeCompiler = null;
    private ?DirectiveCompiler $directiveCompiler = null;

    public function __construct(string $cachePath)
    {
        $this->cachePath = dir_path($cachePath);

        // Ensure the cache directory exists and is writable
        if (!@File::ensureDirectoryWritable($this->cachePath) || !is_writable($this->cachePath)) {
            throw new RuntimeException("View cache directory is not writable: {$this->cachePath}");
        }
    }

    /**
     * Compile a template file
     * 
     * @param string $templatePath
     * @param string $compiledPath
     * @return void
     * @throws RuntimeException
     */
    public function compile(string $templatePath, string $compiledPath): void
    {
        $content = @file_get_contents($templatePath);

        if ($content === false) {
            throw new RuntimeException("Failed to read template file: {$templatePath}");
        }

        $compiled = $this->compileString($content);

        $result = @file_put_contents($compiledPath, $compiled);

        if ($result === false) {
            throw new RuntimeException("Failed to write compiled template: {$compiledPath}");
        }
    }

    /**
     * Compile template string
     * 
     * @param string $template
     * @return string
     */
    public function compileString(string $template): string
    {
        $previousBlocks = [$this->rawBlocks, $this->literalBlocks, $this->expressionBlocks];
        $this->rawBlocks = [];
        $this->literalBlocks = [];
        $this->expressionBlocks = [];

        try {
            // 1. Raw blocks & comments preservation
            if (str_contains($template, '{') || str_contains($template, '@') || str_contains($template, '<?')) {
                $template = $this->getRawBlockCompiler()->preserveRawBlocks($template);
            }

            // 2. <x-component /> tags (only loaded/invoked when template contains '<x-' or '</x-')
            if (str_contains($template, '<x-') || str_contains($template, '</x-')) {
                $template = $this->getComponentCompiler()->compile($template);
            }

            // 3. Inline @php blocks & echoes
            if (str_contains($template, '@php(') || str_contains($template, '{{') || str_contains($template, '{!!')) {
                $template = $this->getEchoCompiler()->compile($template);
            }

            // 4. Layouts, sections, yields, and use directives
            if (
                str_contains($template, '@extends') ||
                str_contains($template, '@section') ||
                str_contains($template, '@endsection') ||
                str_contains($template, '@stop') ||
                str_contains($template, '@show') ||
                str_contains($template, '@yield') ||
                str_contains($template, '@use')
            ) {
                $template = $this->getLayoutCompiler()->compile($template);
            }

            // 5. Includes (@include, @includeIf, @includeWhen)
            if (str_contains($template, '@include')) {
                $template = $this->getIncludeCompiler()->compile($template);
            }

            // 6. Directives (@if, @foreach, custom directives, etc.)
            if (str_contains($template, '@')) {
                $template = $this->getDirectiveCompiler()->compile($template);
            }

            return strtr($template, $this->rawBlocks + $this->expressionBlocks);
        } finally {
            [$this->rawBlocks, $this->literalBlocks, $this->expressionBlocks] = $previousBlocks;
        }
    }

    public function storeRawBlock(string $content, bool $literal = false): string
    {
        $key = '__BLADE_RAW_' . bin2hex(random_bytes(16)) . '__';
        $this->rawBlocks[$key] = $content;

        if ($literal) {
            $this->literalBlocks[$key] = $content;
        }

        return $key;
    }

    public function storeExpression(string $expression): string
    {
        $key = '__BLADE_EXPRESSION_' . bin2hex(random_bytes(16)) . '__';
        $this->expressionBlocks[$key] = $expression;

        return $key;
    }

    public function getRawBlocks(): array
    {
        return $this->rawBlocks;
    }

    public function getLiteralBlocks(): array
    {
        return $this->literalBlocks;
    }

    public function getExpressionBlocks(): array
    {
        return $this->expressionBlocks;
    }

    public function getRawBlockCompiler(): RawBlockCompiler
    {
        return $this->rawBlockCompiler ??= new RawBlockCompiler($this);
    }

    public function getComponentCompiler(): ComponentCompiler
    {
        return $this->componentCompiler ??= new ComponentCompiler($this);
    }

    public function getEchoCompiler(): EchoCompiler
    {
        return $this->echoCompiler ??= new EchoCompiler();
    }

    public function getLayoutCompiler(): LayoutCompiler
    {
        return $this->layoutCompiler ??= new LayoutCompiler($this);
    }

    public function getIncludeCompiler(): IncludeCompiler
    {
        return $this->includeCompiler ??= new IncludeCompiler($this);
    }

    public function getDirectiveCompiler(): DirectiveCompiler
    {
        return $this->directiveCompiler ??= new DirectiveCompiler($this);
    }

    /**
     * Compile single-line directives like @break, @continue, etc.
     */
    public function compileSingleLineDirective(string $template, string $directive, string $phpCode): string
    {
        return $this->getDirectiveCompiler()->compileSingleLineDirective($template, $directive, $phpCode);
    }

    /**
     * Register a custom directive
     * 
     * @param string $name
     * @param callable $callback
     * @return void
     */
    public function directive(string $name, callable $callback): void
    {
        $this->customDirectives[$name] = $callback;
    }

    /**
     * Get registered custom directives
     */
    public function getCustomDirectives(): array
    {
        return $this->customDirectives;
    }

    /**
     * Get compiled template path
     * 
     * @param string $template
     * @return string
     */
    public function getCompiledPath(string $template): string
    {
        return $this->cachePath . '/' . str_replace(['/', '.', '::'], '_', $template) . '_' . md5($template) . '.php';
    }

    /**
     * Check if template needs recompilation
     * 
     * @param string $templatePath
     * @param string $compiledPath
     * @return bool
     */
    public function isExpired(string $templatePath, string $compiledPath): bool
    {
        if (!file_exists($compiledPath)) {
            return true;
        }

        $templateTime = @filemtime($templatePath);
        $compiledTime = @filemtime($compiledPath);

        if ($templateTime === false || $compiledTime === false) {
            return true;
        }

        return $templateTime > $compiledTime;
    }

    /**
     * Clear compiled templates cache
     * 
     * @return void
     */
    public function clearCache(): void
    {
        if (!is_dir($this->cachePath)) {
            return;
        }

        $files = glob("{$this->cachePath}/*.php");

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            is_file($file) && @unlink($file);
        }
    }
}
