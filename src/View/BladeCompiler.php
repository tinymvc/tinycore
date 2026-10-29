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
use function str_replace;
use function strtr;
use function unlink;

/**
 * Class BladeCompiler
 * 
 * Compiles Blade-like templates to PHP using dedicated sub-compilers.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class BladeCompiler implements BladeCompilerContract
{
    use ParsesBladeSyntax;

    public const EXPRESSION_DIRECTIVES = RawBlockCompiler::EXPRESSION_DIRECTIVES;

    /** Path to store compiled templates */
    private string $cachePath;

    /* Array of custom directives */
    private array $customDirectives = [];

    /* Raw PHP blocks to preserve during compilation */
    private array $rawBlocks = [];

    /* Literal blocks to preserve during compilation */
    private array $literalBlocks = [];

    /* Expression blocks to preserve during compilation */
    private array $expressionBlocks = [];

    /** Compiler instances */
    private RawBlockCompiler $rawBlockCompiler;
    private ComponentCompiler $componentCompiler;
    private EchoCompiler $echoCompiler;
    private LayoutCompiler $layoutCompiler;
    private IncludeCompiler $includeCompiler;
    private DirectiveCompiler $directiveCompiler;

    public function __construct(string $cachePath)
    {
        $this->cachePath = dir_path($cachePath);

        // Ensure the cache directory exists and is writable
        if (!@File::ensureDirectoryWritable($this->cachePath) || !is_writable($this->cachePath)) {
            throw new RuntimeException("View cache directory is not writable: {$this->cachePath}");
        }

        $this->initializeCompilers();
    }

    private function initializeCompilers(): void
    {
        $this->rawBlockCompiler = new RawBlockCompiler($this);
        $this->componentCompiler = new ComponentCompiler($this);
        $this->echoCompiler = new EchoCompiler();
        $this->layoutCompiler = new LayoutCompiler($this);
        $this->includeCompiler = new IncludeCompiler($this);
        $this->directiveCompiler = new DirectiveCompiler($this);
    }

    public function __clone(): void
    {
        $this->initializeCompilers();
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
            $template = $this->rawBlockCompiler->preserveRawBlocks($template);
            $template = $this->componentCompiler->compile($template);
            $template = $this->echoCompiler->compile($template);
            $template = $this->layoutCompiler->compile($template);
            $template = $this->includeCompiler->compile($template);
            $template = $this->directiveCompiler->compile($template);

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
        return $this->rawBlockCompiler;
    }

    public function getComponentCompiler(): ComponentCompiler
    {
        return $this->componentCompiler;
    }

    public function getEchoCompiler(): EchoCompiler
    {
        return $this->echoCompiler;
    }

    public function getLayoutCompiler(): LayoutCompiler
    {
        return $this->layoutCompiler;
    }

    public function getIncludeCompiler(): IncludeCompiler
    {
        return $this->includeCompiler;
    }

    public function getDirectiveCompiler(): DirectiveCompiler
    {
        return $this->directiveCompiler;
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
