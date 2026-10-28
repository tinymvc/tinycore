<?php

namespace Spark\View\Compilers;

use InvalidArgumentException;
use Spark\View\BladeCompiler;
use Spark\View\Compilers\Concerns\ParsesBladeSyntax;
use function array_fill;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strtr;
use function substr_count;
use function substr_replace;
use const PREG_OFFSET_CAPTURE;

/**
 * Class DirectiveCompiler
 * 
 * Compiles conditional, loop, helper, single-line, and custom directives.
 */
class DirectiveCompiler
{
    use ParsesBladeSyntax;

    private const CONDITIONAL_DIRECTIVES = [
        'if' => ['open' => 'if(%s):', 'close' => 'endif;'],
        'elseif' => ['open' => 'elseif(%s):', 'close' => null],
        'unless' => ['open' => 'if(!(%s)):', 'close' => 'endif;'],
        'isset' => ['open' => 'if(isset(%s)):', 'close' => 'endif;'],
        'empty' => ['open' => 'if(empty(%s)):', 'close' => 'endif;'],
        'can' => ['open' => 'if(can(%s)):', 'close' => 'endif;'],
        'cannot' => ['open' => 'if(cannot(%s)):', 'close' => 'endif;'],
        'hasSection' => ['open' => 'if($this->hasSection(%s)):', 'close' => 'endif;'],
        'sectionMissing' => ['open' => 'if(!$this->hasSection(%s)):', 'close' => 'endif;'],
        'session' => ['open' => 'if(session()->has(%s)):', 'close' => 'endif;'],
        'switch' => ['open' => 'switch(%s):case 1.9996931348623157e+308:break;', 'close' => 'endswitch;'],
    ];

    private const LOOP_DIRECTIVES = [
        'foreach' => ['open' => 'foreach(%s):', 'close' => 'endforeach;'],
        'for' => ['open' => 'for(%s):', 'close' => 'endfor;'],
        'while' => ['open' => 'while(%s):', 'close' => 'endwhile;'],
    ];

    private const OUTPUT_DIRECTIVES = [
        'dump' => 'dump(%s);',
        'dd' => 'dd(%s);',
        'props' => 'extract($attributes->props(%s));',
        'abort' => 'abort(%s);',
        'old' => 'echo e(old(%s));',
        'share' => '$this->share(%s);',
        'authorize' => 'authorize(%s);',
        'json' => 'echo \Spark\Support\Js::from(%s);',
        'case' => 'case %s:',
        'vite' => 'echo vite(%s);',
        'auth' => 'if(is_logged(%s)):',
        'guest' => 'if(is_guest(%s)):',
        'method' => 'echo method(%s);',
        'checked' => "echo (%s) ? 'checked' : '';",
        'disabled' => "echo (%s) ? 'disabled' : '';",
        'selected' => "echo (%s) ? 'selected' : '';",
        'readonly' => "echo (%s) ? 'readonly' : '';",
        'required' => "echo (%s) ? 'required' : '';",
        'style' => "echo \$this->compileAttributes(['style' => %s]);",
        'class' => "echo \$this->compileAttributes(['class' => %s]);",
        'attributes' => "echo \$this->compileAttributes(%s);",
        'errors' => "if(errors()->any() && errors()->has(%s)): foreach(errors()->get(%s) as \$message):",
        'error' => "if(errors()->any() && errors()->has(%s)): \$message = errors()->first(%s);",
    ];

    private const SINGLE_LINE_DIRECTIVES = [
        'break' => '<?php break; ?>',
        'continue' => '<?php continue; ?>',
        'default' => '<?php default: ?>',
        'vite' => '<?= vite(); ?>',
        'csrf' => '<?= csrf(); ?>',
        'else' => '<?php else: ?>',
        'endif' => '<?php endif; ?>',
        'enderrors' => '<?php endforeach; endif; ?>',
        'enderror' => '<?php endif; ?>',
        'auth' => '<?php if(is_logged()): ?>',
        'guest' => '<?php if(is_guest()): ?>',
        'endauth' => '<?php endif; ?>',
        'endguest' => '<?php endif; ?>',
    ];

    public function __construct(private BladeCompiler $compiler)
    {
    }

    /**
     * Compile directives (@if, @foreach, etc.)
     */
    public function compile(string $template): string
    {
        // Compile conditional directives
        foreach (self::CONDITIONAL_DIRECTIVES as $directive => $config) {
            if (!str_contains($template, "@$directive") && !str_contains($template, "@end$directive")) {
                continue;
            }

            $template = $this->compileOutsidePhp($template, fn($source) => $this->compileDirectiveWithExpression(
                $source,
                $directive,
                $config['open'],
                $config['close']
            ));
        }

        // Compile loop directives
        foreach (self::LOOP_DIRECTIVES as $directive => $config) {
            if (!str_contains($template, "@$directive") && !str_contains($template, "@end$directive")) {
                continue;
            }

            $template = $this->compileOutsidePhp($template, fn($source) => $this->compileDirectiveWithExpression(
                $source,
                $directive,
                $config['open'],
                $config['close']
            ));
        }

        // Compile output directives
        foreach (self::OUTPUT_DIRECTIVES as $directive => $phpCode) {
            if (str_contains($template, "@$directive")) {
                $template = $this->compileOutsidePhp($template, fn($source) => $this->compileDirectiveWithExpression(
                    $source,
                    $directive,
                    $phpCode
                ));
            }
        }

        // Compile custom directives
        $template = $this->compileCustomDirectives($template);

        // Compile single-line directives
        foreach (self::SINGLE_LINE_DIRECTIVES as $directive => $phpCode) {
            if (str_contains($template, "@$directive")) {
                $template = $this->compileOutsidePhp($template, fn($source) => $this->compileSingleLineDirective(
                    $source,
                    $directive,
                    $phpCode
                ));
            }
        }

        return $template;
    }

    /**
     * Compile directive with expression using proper parentheses matching
     */
    public function compileDirectiveWithExpression(string $template, string $directive, string $openTemplate, ?string $closeTemplate = null): string
    {
        $pattern = '/\@' . preg_quote($directive, '/') . '\s*\(/';
        $offset = 0;

        while (preg_match($pattern, $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $parenStart = $matchStart + strlen($matches[0][0]) - 1;

            $expression = $this->extractBalancedExpression($template, $parenStart);

            if ($expression === false) {
                throw new InvalidArgumentException("Unbalanced parentheses in @{$directive} directive");
            }

            $replacement = sprintf('<?php %s ?>', $this->sprintfRepeat($openTemplate, $expression));
            $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
            $template = substr_replace($template, $replacement, $matchStart, $directiveLength);
            $offset = $matchStart + strlen($replacement);
        }

        if ($closeTemplate) {
            $closeDirective = "end$directive";
            $template = $this->compileOutsidePhp($template, fn($source) => preg_replace(
                '/\@' . preg_quote($closeDirective, '/') . '\b/',
                sprintf('<?php %s ?>', $closeTemplate),
                $source
            ));
        }

        return $template;
    }

    /**
     * Helper to repeat expression in sprintf template
     */
    public function sprintfRepeat(string $template, string $expression): string
    {
        $count = substr_count($template, '%s');
        $args = array_fill(0, $count, $expression);

        return sprintf($template, ...$args);
    }

    /**
     * Compile single-line directives like @break, @continue, etc.
     */
    public function compileSingleLineDirective(string $template, string $directive, string $phpCode): string
    {
        return preg_replace('/\@' . preg_quote($directive, '/') . '\b/', $phpCode, $template);
    }

    /**
     * Compile custom directives
     */
    public function compileCustomDirectives(string $template): string
    {
        $customDirectives = $this->compiler->getCustomDirectives();

        foreach ($customDirectives as $directive => $callback) {
            if (!str_contains($template, "@$directive")) {
                continue;
            }

            $template = $this->compileOutsidePhp($template, function ($source) use ($directive, $callback) {
                $pattern = '/\\@' . preg_quote($directive, '/') . '\\b(?:\\s*\\()?/';
                $offset = 0;

                while (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                    $start = $matches[0][1];
                    $matched = $matches[0][0];
                    $length = strlen($matched);
                    $expression = '';

                    if (str_ends_with($matched, '(')) {
                        $expression = $this->extractBalancedExpression($source, $start + $length - 1);

                        if ($expression === false) {
                            throw new InvalidArgumentException("Unbalanced parentheses in @{$directive} directive");
                        }

                        $length += strlen($expression) + 1;
                    }

                    $replacement = $callback(strtr($expression, $this->compiler->getExpressionBlocks()));
                    $source = substr_replace($source, $replacement, $start, $length);
                    $offset = $start + strlen($replacement);
                }

                return $source;
            });
        }

        return $template;
    }
}
