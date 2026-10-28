<?php

namespace Spark\View\Compilers\Concerns;

use function count;
use function in_array;
use function is_array;
use function strlen;
use function substr;
use function token_get_all;
use const PHP_INT_MAX;
use const T_INLINE_HTML;

/**
 * Trait ParsesBladeSyntax
 * 
 * Common parsing routines for Blade template compiling.
 */
trait ParsesBladeSyntax
{
    /**
     * Keep PHP strings, comments, and generated code out of later Blade passes.
     */
    public function compileOutsidePhp(string $template, callable $compiler): string
    {
        if (!str_contains($template, '<?')) {
            return $compiler($template);
        }

        $compiled = '';

        foreach (token_get_all($template) as $token) {
            $compiled .= is_array($token)
                ? ($token[0] === T_INLINE_HTML ? $compiler($token[1]) : $token[1])
                : $token;
        }

        return $compiled;
    }

    /**
     * Extract expression between balanced parentheses
     */
    public function extractBalancedExpression(string $template, int $startPos): string|false
    {
        $length = strlen($template);

        if ($startPos >= $length || $template[$startPos] !== '(') {
            return false;
        }

        $depth = 0;
        $inString = false;
        $stringChar = null;
        $escaped = false;

        for ($i = $startPos; $i < $length; $i++) {
            $char = $template[$i];

            // Handle string literals
            if (!$escaped && ($char === '"' || $char === "'")) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                    $stringChar = null;
                }
            }

            // Handle escape sequences
            if ($char === '\\' && !$escaped) {
                $escaped = true;
                continue;
            }
            $escaped = false;

            // Only count parentheses outside of strings
            if (!$inString) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;

                    if ($depth === 0) {
                        // Found the matching closing parenthesis
                        return substr($template, $startPos + 1, $i - $startPos - 1);
                    }
                }
            }
        }

        // Unbalanced parentheses
        return false;
    }

    /**
     * Split top-level directive arguments respecting nested parentheses and quotes.
     */
    public function splitDirectiveArguments(string $expression, int $limit = PHP_INT_MAX, array $expressionBlocks = []): array
    {
        if (empty($expressionBlocks) && isset($this->expressionBlocks) && is_array($this->expressionBlocks)) {
            $expressionBlocks = $this->expressionBlocks;
        }

        if (!empty($expressionBlocks)) {
            $expression = strtr($expression, $expressionBlocks);
        }

        $args = [];
        $depth = 0;
        $inStr = false;
        $strChar = null;
        $escaped = false;
        $current = '';
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $current .= $char;
                $escaped = true;
                continue;
            }

            if (!$inStr && ($char === '"' || $char === "'")) {
                $inStr = true;
                $strChar = $char;
                $current .= $char;
                continue;
            }

            if ($inStr && $char === $strChar) {
                $inStr = false;
                $strChar = null;
                $current .= $char;
                continue;
            }

            if (!$inStr) {
                if (in_array($char, ['(', '[', '{'])) {
                    $depth++;
                } elseif (in_array($char, [')', ']', '}'])) {
                    $depth--;
                } elseif ($char === ',' && $depth === 0 && count($args) < $limit - 1) {
                    $args[] = trim($current);
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if ($current !== '' || !empty($args)) {
            $args[] = trim($current);
        }

        return $args;
    }
}
