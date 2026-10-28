<?php

namespace Spark\View\Compilers;

use InvalidArgumentException;
use Spark\View\BladeCompiler;
use Spark\View\Compilers\Concerns\ParsesBladeSyntax;
use function count;
use function preg_match;
use function preg_replace_callback;
use function strlen;
use function substr_replace;
use function trim;
use const PREG_OFFSET_CAPTURE;

/**
 * Class LayoutCompiler
 * 
 * Compiles @use, @extends, @section, @stop, @show, and @yield directives.
 */
class LayoutCompiler
{
    use ParsesBladeSyntax;

    public function __construct(private BladeCompiler $compiler)
    {
    }

    /**
     * Compile layout and section directives.
     */
    public function compile(string $template): string
    {
        if (str_contains($template, '@use')) {
            $template = $this->compileOutsidePhp($template, $this->compileUseDirective(...));
        }

        if (str_contains($template, '@extends')) {
            $template = $this->compileOutsidePhp($template, $this->compileExtends(...));
        }

        if (
            str_contains($template, '@section') ||
            str_contains($template, '@endsection') ||
            str_contains($template, '@stop') ||
            str_contains($template, '@show')
        ) {
            $template = $this->compileOutsidePhp($template, $this->compileSections(...));
        }

        if (str_contains($template, '@yield')) {
            $template = $this->compileOutsidePhp($template, $this->compileYields(...));
        }

        return $template;
    }

    /**
     * Compile @use directive
     */
    public function compileUseDirective(string $template): string
    {
        return preg_replace_callback(
            '/\@use\s*\(\s*([^,)]+)(?:\s*,\s*([^,)]+))?\s*\)/s',
            function ($matches) {
                $arguments = $this->splitDirectiveArguments($matches[1], 2, $this->compiler->getExpressionBlocks());
                $classExpr = trim($arguments[0]);
                $aliasExpr = isset($arguments[1]) ? trim($arguments[1]) : null;

                $className = trim($classExpr, '\'"');

                if ($aliasExpr) {
                    $alias = trim($aliasExpr, '\'"');
                    return "<?php use {$className} as {$alias}; ?>";
                }

                return "<?php use {$className}; ?>";
            },
            $template
        );
    }

    /**
     * Compile @extends directive
     */
    public function compileExtends(string $template): string
    {
        $offset = 0;

        while (preg_match('/\@extends\s*\(/', $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $parenStart = $matchStart + strlen($matches[0][0]) - 1;

            $expression = $this->extractBalancedExpression($template, $parenStart);
            if ($expression === false) {
                throw new InvalidArgumentException('Unbalanced parentheses in @extends directive');
            }

            $replacement = '<?php $this->setExtends(' . trim($expression) . '); ?>';
            $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
            $template = substr_replace($template, $replacement, $matchStart, $directiveLength);
            $offset = $matchStart + strlen($replacement);
        }

        return $template;
    }

    /**
     * Compile @section and @endsection directives
     */
    public function compileSections(string $template): string
    {
        $offset = 0;

        while (preg_match('/\@section\s*\(/', $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $parenStart = $matchStart + strlen($matches[0][0]) - 1;

            $expression = $this->extractBalancedExpression($template, $parenStart);
            if ($expression === false) {
                throw new InvalidArgumentException('Unbalanced parentheses in @section directive');
            }

            $args = $this->splitDirectiveArguments($expression, 2, $this->compiler->getExpressionBlocks());
            $section = trim($args[0] ?? '');
            $valueExpression = trim($args[1] ?? '');

            if ($section === '') {
                throw new InvalidArgumentException('Invalid @section directive: section name is missing.');
            }

            if (count($args) >= 2) {
                $replacement = "<?php \$this->startSection({$section}); echo {$valueExpression}; \$this->endSection(); ?>";
            } else {
                $replacement = "<?php \$this->startSection({$section}); ?>";
            }

            $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
            $template = substr_replace($template, $replacement, $matchStart, $directiveLength);
            $offset = $matchStart + strlen($replacement);
        }

        return $this->compileOutsidePhp($template, function ($source) {
            return preg_replace_callback('/@(endsection|stop|show)\b/', fn($matches) => $matches[1] === 'show'
                ? '<?php echo $this->stopAndYieldSection(); ?>'
                : '<?php $this->endSection(); ?>', $source);
        });
    }

    /**
     * Compile @yield directives
     */
    public function compileYields(string $template): string
    {
        $offset = 0;

        while (preg_match('/\@yield\s*\(/', $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $parenStart = $matchStart + strlen($matches[0][0]) - 1;

            $expression = $this->extractBalancedExpression($template, $parenStart);
            if ($expression === false) {
                throw new InvalidArgumentException('Unbalanced parentheses in @yield directive');
            }

            $args = $this->splitDirectiveArguments($expression, 2, $this->compiler->getExpressionBlocks());
            $section = trim($args[0] ?? "''");
            $default = trim($args[1] ?? "''");

            if ($section === '') {
                throw new InvalidArgumentException('Invalid @yield directive: section name is missing.');
            }

            $replacement = "<?= \$this->yieldSection({$section}, {$default}); ?>";

            $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
            $template = substr_replace($template, $replacement, $matchStart, $directiveLength);
            $offset = $matchStart + strlen($replacement);
        }

        return $template;
    }
}
