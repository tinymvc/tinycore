<?php

namespace Spark\View\Compilers;

use InvalidArgumentException;
use Spark\View\BladeCompiler;
use Spark\View\Compilers\Concerns\ParsesBladeSyntax;
use function preg_match;
use function preg_quote;
use function strlen;
use function substr_replace;
use function trim;
use const PREG_OFFSET_CAPTURE;

/**
 * Class IncludeCompiler
 * 
 * Compiles @include, @includeIf, and @includeWhen directives.
 */
class IncludeCompiler
{
    use ParsesBladeSyntax;

    public function __construct(private BladeCompiler $compiler)
    {
    }

    /**
     * Compile @include directives
     */
    public function compile(string $template): string
    {
        $directives = [
            'includeWhen' => function (string $expression): string {
                $args = $this->splitDirectiveArguments($expression, 3, $this->compiler->getExpressionBlocks());
                $condition = $args[0] ?? 'false';
                $viewExpr = $args[1] ?? "''";
                $dataExpr = $args[2] ?? '[]';
                return "<?php if ({$condition}): echo \$this->include({$viewExpr}, {$dataExpr}); endif; ?>";
            },
            'includeIf' => function (string $expression): string {
                $args = $this->splitDirectiveArguments($expression, 2, $this->compiler->getExpressionBlocks());
                $viewExpr = $args[0] ?? "''";
                $dataExpr = $args[1] ?? '[]';
                return "<?php if (\$this->templateExists({$viewExpr})): echo \$this->include({$viewExpr}, {$dataExpr}); endif; ?>";
            },
            'include' => function (string $expression): string {
                $args = $this->splitDirectiveArguments($expression, 2, $this->compiler->getExpressionBlocks());
                $viewExpr = $args[0] ?? "''";
                $dataExpr = $args[1] ?? '[]';
                return "<?= \$this->include({$viewExpr}, {$dataExpr}); ?>";
            },
        ];

        foreach ($directives as $directive => $handler) {
            if (!str_contains($template, "@$directive")) {
                continue;
            }

            $template = $this->compileOutsidePhp($template, function ($source) use ($directive, $handler) {
                $pattern = '/\@' . preg_quote($directive, '/') . '\s*\(/';
                $offset = 0;

                while (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                    $matchStart = $matches[0][1];
                    $parenStart = $matchStart + strlen($matches[0][0]) - 1;

                    $expression = $this->extractBalancedExpression($source, $parenStart);

                    if ($expression === false) {
                        throw new InvalidArgumentException("Unbalanced parentheses in @{$directive} directive");
                    }

                    $replacement = $handler(trim($expression));
                    $directiveLength = ($parenStart - $matchStart) + strlen($expression) + 2;
                    $source = substr_replace($source, $replacement, $matchStart, $directiveLength);
                    $offset = $matchStart + strlen($replacement);
                }

                return $source;
            });
        }

        return $template;
    }
}
