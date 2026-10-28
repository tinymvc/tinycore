<?php

namespace Spark\View\Compilers;

use InvalidArgumentException;
use Spark\View\Compilers\Concerns\ParsesBladeSyntax;
use function preg_match;
use function preg_replace_callback;
use function sprintf;
use function strlen;
use function substr;
use function substr_replace;
use function trim;
use const PREG_OFFSET_CAPTURE;

/**
 * Class EchoCompiler
 * 
 * Compiles inline @php(...) blocks and {{ }} / {!! !!} echoes.
 */
class EchoCompiler
{
    use ParsesBladeSyntax;

    /**
     * Compile echoes and inline PHP blocks.
     */
    public function compile(string $template): string
    {
        if (str_contains($template, '@php(')) {
            $template = $this->compileOutsidePhp($template, $this->compilePhpBlocks(...));
        }

        if (str_contains($template, '{{') || str_contains($template, '{!!')) {
            $template = $this->compileOutsidePhp($template, $this->compileEchos(...));
        }

        return $template;
    }

    /**
     * Compile inline @php(...) blocks
     */
    public function compilePhpBlocks(string $template): string
    {
        $pattern = '/\@php\s*\(/';
        $offset = 0;

        while (preg_match($pattern, $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $parenStart = $matchStart + strlen($matches[0][0]) - 1;

            $expression = $this->extractBalancedExpression($template, $parenStart);

            if ($expression === false) {
                throw new InvalidArgumentException('Unbalanced parentheses in @php directive');
            }

            $replacement = sprintf('<?php %s; ?>', $expression);
            $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
            $template = substr_replace($template, $replacement, $matchStart, $directiveLength);
            $offset = $matchStart + strlen($replacement);
        }

        return $template;
    }

    /**
     * Compile echo statements {{ }} and {!! !!}
     */
    public function compileEchos(string $template): string
    {
        return preg_replace_callback('/(@)?(?:\\{!!\\s*(.+?)\\s*!!\\}|\\{\\{\\s*(.+?)\\s*\\}\\})/s', function ($matches) {
            if ($matches[1] === '@') {
                return substr($matches[0], 1);
            }

            return isset($matches[3])
                ? '<?= e(' . trim($matches[3]) . '); ?>'
                : '<?= ' . trim($matches[2]) . '; ?>';
        }, $template);
    }
}
