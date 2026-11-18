<?php

namespace Spark\View\Compilers;

use InvalidArgumentException;
use Spark\View\BladeCompiler;
use Spark\View\Compilers\Concerns\ParsesBladeSyntax;
use function array_fill;
use function array_slice;
use function count;
use function in_array;
use function is_array;
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
        'elsecan' => ['open' => 'elseif(can(%s)):', 'close' => null],
        'elsecannot' => ['open' => 'elseif(cannot(%s)):', 'close' => null],
        'elsecanany' => ['open' => 'elseif(canAny(%s)):', 'close' => null],
        'unless' => ['open' => 'if(!(%s)):', 'close' => 'endif;'],
        'isset' => ['open' => 'if(isset(%s)):', 'close' => 'endif;'],
        'empty' => ['open' => 'if(empty(%s)):', 'close' => 'endif;'],
        'can' => ['open' => 'if(can(%s)):', 'close' => 'endif;'],
        'cannot' => ['open' => 'if(cannot(%s)):', 'close' => 'endif;'],
        'canany' => ['open' => 'if(canAny(%s)):', 'close' => 'endif;'],
        'hasSection' => ['open' => 'if($this->hasSection(%s)):', 'close' => 'endif;'],
        'sectionMissing' => ['open' => 'if(!$this->hasSection(%s)):', 'close' => 'endif;'],
        'session' => ['open' => 'if(session()->has($__bladeSessionKey = %s)): $value = session()->get($__bladeSessionKey);', 'close' => 'endif;'],
        'env' => ['open' => 'if(app()->environment(%s)):', 'close' => 'endif;'],
        'switch' => ['open' => 'switch(%s):case 1.9996931348623157e+308:break;', 'close' => 'endswitch;'],
    ];

    private const LOOP_DIRECTIVES = [
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
        'js' => 'echo \Spark\Support\Js::from(%s);',
        'case' => 'case %s:',
        'break' => 'if(%s) break;',
        'continue' => 'if(%s) continue;',
        'vite' => 'echo vite(%s);',
        'auth' => 'if(is_logged(%s)):',
        'guest' => 'if(is_guest(%s)):',
        'elseauth' => 'elseif(is_logged(%s)):',
        'elseguest' => 'elseif(is_guest(%s)):',
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
        'endcanany' => '<?php endif; ?>',
        'enderrors' => '<?php endforeach; endif; ?>',
        'enderror' => '<?php endif; ?>',
        'auth' => '<?php if(is_logged()): ?>',
        'guest' => '<?php if(is_guest()): ?>',
        'elseauth' => '<?php elseif(is_logged()): ?>',
        'elseguest' => '<?php elseif(is_guest()): ?>',
        'endauth' => '<?php endif; ?>',
        'endguest' => '<?php endif; ?>',
        'production' => '<?php if(app()->environment() === "production"): ?>',
        'endproduction' => '<?php endif; ?>',
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
            if (stripos($template, "@$directive") === false && stripos($template, "@end$directive") === false) {
                continue;
            }

            $template = $this->compileOutsidePhp($template, fn($source) => $this->compileDirectiveWithExpression(
                $source,
                $directive,
                $config['open'],
                $config['close']
            ));
        }

        // Compile forelse loops
        if (stripos($template, '@forelse') !== false) {
            $template = $this->compileForelse($template);
        }

        // Compile foreach loops
        if (stripos($template, '@foreach') !== false || stripos($template, '@endforeach') !== false) {
            $template = $this->compileForeach($template);
        }

        // Compile loop directives
        foreach (self::LOOP_DIRECTIVES as $directive => $config) {
            if (stripos($template, "@$directive") === false && stripos($template, "@end$directive") === false) {
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
            if (stripos($template, "@$directive") !== false) {
                $template = $this->compileOutsidePhp($template, fn($source) => $this->compileDirectiveWithExpression(
                    $source,
                    $directive,
                    $phpCode
                ));
            }
        }

        // Compile custom directives
        $template = $this->compileCustomDirectives($template);

        // Compile inject directives
        if (stripos($template, '@inject') !== false) {
            $template = $this->compileInject($template);
        }

        // Compile single-line directives
        foreach (self::SINGLE_LINE_DIRECTIVES as $directive => $phpCode) {
            if (stripos($template, "@$directive") !== false) {
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
        $pattern = '/(?<!@)\@' . preg_quote($directive, '/') . '\s*\(/i';
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
                '/(?<!@)\@' . preg_quote($closeDirective, '/') . '\b/i',
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
        return preg_replace('/(?<!@)\@' . preg_quote($directive, '/') . '\b/i', $phpCode, $template);
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

    /**
     * Compile foreach loops with $loop tracking
     */
    public function compileForeach(string $template): string
    {
        return $this->compileOutsidePhp($template, function (string $source) {
            $pattern = '/(?<!@)\@foreach\s*\(/i';
            $offset = 0;

            while (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $matchStart = $matches[0][1];
                $parenStart = $matchStart + strlen($matches[0][0]) - 1;

                $expression = $this->extractBalancedExpression($source, $parenStart);

                if ($expression === false) {
                    throw new InvalidArgumentException('Unbalanced parentheses in @foreach directive');
                }

                $rawExpression = strtr($expression, $this->compiler->getExpressionBlocks());

                [$iteratee, $iteration] = $this->splitLoopExpression($rawExpression, 'foreach');

                $replacement = sprintf(
                    '<?php unset($__currentLoopData); $__currentLoopData = %s; $this->addLoop($__currentLoopData); foreach($__currentLoopData as %s): $this->incrementLoopIndices(); $loop = $this->getLastLoop(); ?>',
                    $this->loopDataExpression($iteratee, $iteration),
                    $iteration
                );

                $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
                $source = substr_replace($source, $replacement, $matchStart, $directiveLength);
                $offset = $matchStart + strlen($replacement);
            }

            return $this->compileOutsidePhp($source, fn(string $html) => preg_replace(
                '/(?<!@)\@endforeach\b/i',
                '<?php endforeach; $this->popLoop(); $loop = $this->getLastLoop(); ?>',
                $html
            ));
        });
    }

    /**
     * Compile forelse loops with @empty, @endforelse, and $loop tracking
     */
    public function compileForelse(string $template): string
    {
        $counter = 0;
        $stack = [];

        return $this->compileOutsidePhp($template, function (string $source) use (&$counter, &$stack) {
            $pattern = '/(?<!@)\@(?:forelse\s*\(|empty\b(?!\s*\()|endforelse\b)/i';
            $offset = 0;

            while (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $matchStart = $matches[0][1];
                $matched = strtolower($matches[0][0]);

                if (str_starts_with($matched, '@forelse')) {
                    $parenStart = $matchStart + strlen($matches[0][0]) - 1;
                    $expression = $this->extractBalancedExpression($source, $parenStart);

                    if ($expression === false) {
                        throw new InvalidArgumentException('Unbalanced parentheses in @forelse directive');
                    }

                    $rawExpression = strtr($expression, $this->compiler->getExpressionBlocks());

                    [$iteratee, $iteration] = $this->splitLoopExpression($rawExpression, 'forelse');

                    $counter++;
                    $stack[] = ['id' => $counter, 'has_empty' => false];
                    $replacement = sprintf(
                        '<?php $__empty_%d = true; unset($__currentLoopData); $__currentLoopData = %s; $this->addLoop($__currentLoopData); foreach($__currentLoopData as %s): $__empty_%d = false; $this->incrementLoopIndices(); $loop = $this->getLastLoop(); ?>',
                        $counter,
                        $this->loopDataExpression($iteratee, $iteration),
                        $iteration,
                        $counter
                    );
                    $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
                    $source = substr_replace($source, $replacement, $matchStart, $directiveLength);
                    $offset = $matchStart + strlen($replacement);
                    continue;
                }

                if ($matched === '@empty') {
                    if (empty($stack) || end($stack)['has_empty']) {
                        $offset = $matchStart + strlen($matches[0][0]);
                        continue;
                    }

                    $stack[array_key_last($stack)]['has_empty'] = true;
                    $current = end($stack)['id'];
                    $replacement = sprintf('<?php endforeach; $this->popLoop(); $loop = $this->getLastLoop(); if ($__empty_%d): ?>', $current);
                    $source = substr_replace($source, $replacement, $matchStart, strlen($matches[0][0]));
                    $offset = $matchStart + strlen($replacement);
                    continue;
                }

                if ($matched === '@endforelse') {
                    if (empty($stack)) {
                        $offset = $matchStart + strlen($matches[0][0]);
                        continue;
                    }

                    $entry = array_pop($stack);
                    $replacement = $entry['has_empty']
                        ? '<?php endif; ?>'
                        : '<?php endforeach; $this->popLoop(); $loop = $this->getLastLoop(); ?>';
                    $source = substr_replace($source, $replacement, $matchStart, strlen($matches[0][0]));
                    $offset = $matchStart + strlen($replacement);
                    continue;
                }
            }

            return $source;
        });
    }

    private function splitLoopExpression(string $expression, string $directive): array
    {
        $iteratee = '';
        $iteration = '';
        $depth = 0;
        $foundAs = false;

        foreach (array_slice(token_get_all("<?php $expression"), 1) as $token) {
            if (is_array($token) && $token[0] === T_AS && $depth === 0 && !$foundAs) {
                $foundAs = true;
                continue;
            }

            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            }
            if (!is_array($token)) {
                if (in_array($token, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($token, [')', ']', '}'], true)) {
                    $depth--;
                }
            }

            if ($foundAs) {
                $iteration .= $text;
            } else {
                $iteratee .= $text;
            }
        }

        if (!$foundAs || trim($iteratee) === '' || trim($iteration) === '') {
            throw new InvalidArgumentException("Malformed @{$directive} expression: {$expression}");
        }

        // Preserve newlines that terminate PHP line comments.
        return [$iteratee, $iteration];
    }

    private function loopDataExpression(string $iteratee, string $iteration): string
    {
        // Keep writes through a referenced array variable visible to the template.
        $variable = trim($iteratee);
        if (
            preg_match('/^(?:.*=>\s*)?&\s*\$/s', ltrim($iteration))
            && preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/', $variable)
        ) {
            return "&$variable";
        }

        return $iteratee;
    }

    /**
     * Compile @inject directive: @inject('var', 'Service')
     */
    public function compileInject(string $template): string
    {
        return $this->compileOutsidePhp($template, function (string $source) {
            $pattern = '/(?<!@)\@inject\s*\(/i';
            $offset = 0;

            while (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $matchStart = $matches[0][1];
                $parenStart = $matchStart + strlen($matches[0][0]) - 1;

                $expression = $this->extractBalancedExpression($source, $parenStart);

                if ($expression === false) {
                    throw new InvalidArgumentException('Unbalanced parentheses in @inject directive');
                }

                $args = $this->splitDirectiveArguments($expression, expressionBlocks: $this->compiler->getExpressionBlocks());
                if (count($args) > 2 && end($args) === '') {
                    array_pop($args);
                }

                if (count($args) !== 2 || in_array('', $args, true)) {
                    throw new InvalidArgumentException('The @inject directive requires a variable name and a service name.');
                }

                $varName = ltrim(trim($args[0], '\'" '), '$');

                if (in_array($varName, ['', 'this', 'GLOBALS'], true) || !preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $varName)) {
                    throw new InvalidArgumentException("Invalid variable name [{$args[0]}] in @inject directive.");
                }

                $service = trim($args[1]);

                $replacement = sprintf('<?php $%s = app(%s); ?>', $varName, $service);
                $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
                $source = substr_replace($source, $replacement, $matchStart, $directiveLength);
                $offset = $matchStart + strlen($replacement);
            }

            return $source;
        });
    }
}
