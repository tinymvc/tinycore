<?php

namespace Spark\View\Compilers;

use InvalidArgumentException;
use Spark\View\BladeCompiler;
use function array_shift;
use function ctype_space;
use function in_array;
use function is_array;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function substr_compare;
use function token_get_all;
use function trim;
use const PREG_OFFSET_CAPTURE;
use const T_CLOSE_TAG;
use const T_CURLY_OPEN;
use const T_DOLLAR_OPEN_CURLY_BRACES;

/**
 * Class RawBlockCompiler
 * 
 * Preserves raw and literal regions (comments, verbatim, PHP blocks, expressions)
 * in source order so their contents stay opaque during compilation passes.
 */
class RawBlockCompiler
{
    public const EXPRESSION_DIRECTIVES = [
        'use',
        'extends',
        'section',
        'yield',
        'include',
        'includeIf',
        'includeWhen',
        'php',
        'if',
        'elseif',
        'unless',
        'isset',
        'empty',
        'can',
        'cannot',
        'hasSection',
        'sectionMissing',
        'session',
        'switch',
        'foreach',
        'for',
        'while',
        'dump',
        'dd',
        'props',
        'abort',
        'old',
        'share',
        'authorize',
        'json',
        'case',
        'vite',
        'auth',
        'guest',
        'method',
        'checked',
        'disabled',
        'selected',
        'readonly',
        'required',
        'style',
        'class',
        'attributes',
        'errors',
        'error',
    ];

    public function __construct(private BladeCompiler $compiler)
    {
    }

    /**
     * Preserve raw regions in source order so their contents stay opaque.
     */
    public function preserveRawBlocks(string $template): string
    {
        $pattern = '/\{\{--|@?\{!!|@?\{\{|@@?[a-zA-Z_]\w*|<\?(?:[pP][hH][pP](?=\s|$)|=)/';
        $result = '';
        $offset = 0;
        $customDirectives = $this->compiler->getCustomDirectives();

        while (preg_match($pattern, $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $matches[0][1];
            $opening = $matches[0][0];
            $contentStart = $start + strlen($opening);
            $result .= substr($template, $offset, $start - $offset);

            if ($opening === '{{--') {
                $end = strpos($template, '--}}', $contentStart);

                if ($end === false) {
                    return $result . substr($template, $start);
                }

                $offset = $end + 4;
                continue;
            }

            if (str_contains($opening, '{')) {
                $closing = str_contains($opening, '!!') ? '!!}' : '}}';
                $end = $this->findExpressionEnd($template, $contentStart, $closing);
                $content = substr($template, $contentStart, $end - $contentStart);

                if (str_starts_with($opening, '@')) {
                    $result .= $this->compiler->storeRawBlock(substr($opening, 1) . $content . $closing, true);
                } else {
                    $result .= $opening . $this->compiler->storeExpression($content) . $closing;
                }

                $offset = $end + strlen($closing);
                continue;
            }

            if ($opening[0] === '@') {
                $directive = ltrim($opening, '@');

                // CSS at-rules and plain text do not contain Blade PHP arguments.
                if (
                    $directive !== 'verbatim'
                    && !in_array($directive, self::EXPRESSION_DIRECTIVES, true)
                    && !isset($customDirectives[$directive])
                ) {
                    $result .= str_starts_with($opening, '@@')
                        ? $this->compiler->storeRawBlock(substr($opening, 1), true)
                        : $opening;
                    $offset = $contentStart;
                    continue;
                }

                $paren = $contentStart;

                while (isset($template[$paren]) && ctype_space($template[$paren])) {
                    $paren++;
                }

                if (($template[$paren] ?? '') === '(' && $opening !== '@verbatim') {
                    $end = $this->findExpressionEnd($template, $paren + 1, ')');
                    $content = substr($template, $paren + 1, $end - $paren - 1);

                    if (str_starts_with($opening, '@@')) {
                        $result .= $this->compiler->storeRawBlock(substr($template, $start + 1, $end - $start), true);
                    } else {
                        $result .= $opening . '(' . $this->compiler->storeExpression($content) . ')';
                    }

                    $offset = $end + 1;
                    continue;
                }

                if (str_starts_with($opening, '@@')) {
                    $result .= $this->compiler->storeRawBlock(substr($opening, 1), true);
                    $offset = $contentStart;
                    continue;
                }

                if ($opening !== '@verbatim' && $opening !== '@php') {
                    $result .= $opening;
                    $offset = $contentStart;
                    continue;
                }
            }

            if ($opening === '@verbatim' || $opening === '@php') {
                $closing = $opening === '@verbatim' ? '@endverbatim' : '@endphp';
                $end = strpos($template, $closing, $contentStart);

                if ($end === false) {
                    throw new InvalidArgumentException("Missing {$closing} directive.");
                }

                $content = substr($template, $contentStart, $end - $contentStart);
                $content = $opening === '@php' ? "<?php\n" . trim($content) . "\n?>" : $content;
                $result .= $this->compiler->storeRawBlock($content, $opening === '@verbatim');
                $offset = $end + strlen($closing);

                continue;
            }

            $php = '';

            foreach (token_get_all(substr($template, $start)) as $token) {
                $php .= is_array($token) ? $token[1] : $token;

                if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                    break;
                }
            }

            $result .= $this->compiler->storeRawBlock($php);
            $offset = $start + strlen($php);
        }

        return $result . substr($template, $offset);
    }

    /**
     * PHP tokens keep quotes, comments, heredocs, and nested brackets opaque.
     */
    public function findExpressionEnd(string $template, int $start, string $closing): int
    {
        $offset = $start;
        $depth = 0;
        $tokens = token_get_all('<?php ' . substr($template, $start));
        array_shift($tokens);

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (!is_array($token)) {
                if ($depth === 0 && substr_compare($template, $closing, $offset, strlen($closing)) === 0) {
                    return $offset;
                }

                if (in_array($token, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($token, [')', ']', '}'], true)) {
                    $depth--;
                }
            } elseif (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            }

            $offset += strlen($text);
        }

        throw new InvalidArgumentException("Unclosed Blade expression; expected [{$closing}].");
    }
}
