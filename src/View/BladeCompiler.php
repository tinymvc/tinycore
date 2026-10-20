<?php

namespace Spark\View;

use InvalidArgumentException;
use Spark\Utils\File;
use Spark\View\Contracts\BladeCompilerContract;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Class BladeCompiler
 * 
 * Compiles Blade-like templates to PHP
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class BladeCompiler implements BladeCompilerContract
{
    private const TAG_PATTERN = '/<(?<closing>\/)?(?<name>[a-zA-Z][a-zA-Z0-9\-_.:]*)(?=[\s\/>])/';

    private const EXPRESSION_DIRECTIVES = [
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

    /**
     * Path to store compiled templates
     * 
     * @var string
     */
    private string $cachePath;

    /**
     * Array of custom directives
     * 
     * @var array
     */
    private array $customDirectives = [];

    /**
     * Raw PHP blocks to preserve during compilation
     * 
     * @var array
     */
    private array $rawBlocks = [];

    /**
     * Literal blocks to preserve during compilation
     * 
     * @var array
     */
    private array $literalBlocks = [];

    /**
     * Expression blocks to preserve during compilation
     * 
     * @var array
     */
    private array $expressionBlocks = [];

    public function __construct(string $cachePath)
    {
        $this->cachePath = dir_path($cachePath);

        // Ensure the cache directory exists and is writable
        if (!@File::ensureDirectoryWritable($this->cachePath) || !is_writable($this->cachePath)) {
            throw new \RuntimeException("View cache directory is not writable: {$this->cachePath}");
        }
    }

    /**
     * Compile a template file
     * 
     * @param string $templatePath
     * @param string $compiledPath
     * @return void
     * @throws \RuntimeException
     */
    public function compile(string $templatePath, string $compiledPath): void
    {
        $content = @file_get_contents($templatePath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read template file: {$templatePath}");
        }

        $compiled = $this->compileString($content);

        $result = @file_put_contents($compiledPath, $compiled);

        if ($result === false) {
            throw new \RuntimeException("Failed to write compiled template: {$compiledPath}");
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
            $template = $this->preserveRawBlocks($template);
            $template = $this->compileXComponents($template);
            $template = $this->compileOutsidePhp($template, $this->compilePhpBlocks(...));
            $template = $this->compileOutsidePhp($template, $this->compileEchos(...));

            foreach ([
                $this->compileUseDirective(...),
                $this->compileExtends(...),
                $this->compileSections(...),
                $this->compileYields(...),
                $this->compileIncludes(...),
            ] as $compiler) {
                $template = $this->compileOutsidePhp($template, $compiler);
            }

            $template = $this->compileDirectives($template);

            return strtr($template, $this->rawBlocks + $this->expressionBlocks);
        } finally {
            [$this->rawBlocks, $this->literalBlocks, $this->expressionBlocks] = $previousBlocks;
        }
    }

    /**
     * Keep PHP strings, comments, and generated code out of later Blade passes.
     */
    private function compileOutsidePhp(string $template, callable $compiler): string
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

    private function storeRawBlock(string $content, bool $literal = false): string
    {
        $key = '__BLADE_RAW_' . bin2hex(random_bytes(16)) . '__';
        $this->rawBlocks[$key] = $content;

        if ($literal) {
            $this->literalBlocks[$key] = $content;
        }

        return $key;
    }

    /**
     * Preserve raw regions in source order so their contents stay opaque.
     */
    private function preserveRawBlocks(string $template): string
    {
        $pattern = '/\{\{--|@?\{!!|@?\{\{|@@?[a-zA-Z_]\w*|<\?(?:[pP][hH][pP](?=\s|$)|=)/';
        $result = '';
        $offset = 0;

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
                    $result .= $this->storeRawBlock(substr($opening, 1) . $content . $closing, true);
                } else {
                    $result .= $opening . $this->storeExpression($content) . $closing;
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
                    && !isset($this->customDirectives[$directive])
                ) {
                    $result .= str_starts_with($opening, '@@')
                        ? $this->storeRawBlock(substr($opening, 1), true)
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
                        $result .= $this->storeRawBlock(substr($template, $start + 1, $end - $start), true);
                    } else {
                        $result .= $opening . '(' . $this->storeExpression($content) . ')';
                    }

                    $offset = $end + 1;
                    continue;
                }

                if (str_starts_with($opening, '@@')) {
                    $result .= $this->storeRawBlock(substr($opening, 1), true);
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
                $result .= $this->storeRawBlock($content, $opening === '@verbatim');
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

            $result .= $this->storeRawBlock($php);
            $offset = $start + strlen($php);
        }

        return $result . substr($template, $offset);
    }

    private function storeExpression(string $expression): string
    {
        $key = '__BLADE_EXPRESSION_' . bin2hex(random_bytes(16)) . '__';
        $this->expressionBlocks[$key] = $expression;

        return $key;
    }

    /**
     * PHP tokens keep quotes, comments, heredocs, and nested brackets opaque.
     */
    private function findExpressionEnd(string $template, int $start, string $closing): int
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

    /**
     * Compile @extends directive
     * 
     * @param string $template
     * @return string
     */
    private function compileExtends(string $template): string
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
    private function compileSections(string $template): string
    {
        $offset = 0;

        while (preg_match('/\@section\s*\(/', $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $parenStart = $matchStart + strlen($matches[0][0]) - 1;

            $expression = $this->extractBalancedExpression($template, $parenStart);
            if ($expression === false) {
                throw new InvalidArgumentException('Unbalanced parentheses in @section directive');
            }

            $args = $this->splitDirectiveArguments($expression, 2);
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
    private function compileYields(string $template): string
    {
        $offset = 0;

        while (preg_match('/\@yield\s*\(/', $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $parenStart = $matchStart + strlen($matches[0][0]) - 1;

            $expression = $this->extractBalancedExpression($template, $parenStart);
            if ($expression === false) {
                throw new InvalidArgumentException('Unbalanced parentheses in @yield directive');
            }

            $args = $this->splitDirectiveArguments($expression, 2);
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

    /**
     * Compile X-Components (<x-component-name>)
     * 
     * @param string $template
     * @return string
     */
    private function compileXComponents(string $template): string
    {
        $offset = 0;

        while (preg_match(self::TAG_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $matches[0][1];
            $tagName = $matches['name'][0];
            $nameEnd = $start + strlen($matches[0][0]);
            $tagEnd = $this->findOpeningTagEnd($template, $nameEnd);
            $isComponent = str_starts_with($tagName, 'x-');

            if ($tagEnd === false) {
                if ($isComponent) {
                    throw new InvalidArgumentException("Unclosed opening tag for component [{$tagName}].");
                }

                break;
            }

            if (!$isComponent) {
                $offset = $tagEnd + 1;
                continue;
            }

            $name = substr($tagName, 2);

            if ($name === '' || $matches['closing'][0] === '/') {
                throw new InvalidArgumentException("Unexpected component tag [{$tagName}].");
            }

            $attributeSource = rtrim(substr($template, $nameEnd, $tagEnd - $nameEnd));
            $selfClosing = str_ends_with($attributeSource, '/');

            if ($selfClosing) {
                $attributeSource = substr($attributeSource, 0, -1);
                $end = $tagEnd + 1;
                $slot = null;
            } else {
                [$closeStart, $end] = $this->findComponentClose($template, $name, $tagEnd + 1);
                $slot = $this->compileXComponents(substr($template, $tagEnd + 1, $closeStart - $tagEnd - 1));
            }

            $attributes = $this->parseXComponentAttributes($attributeSource);
            $attributesArray = $this->buildAttributesArray($attributes, $slot);
            $replacement = "<?= \$this->component('{$name}', {$attributesArray}); ?>";
            $template = substr_replace($template, $replacement, $start, $end - $start);
            $offset = $start + strlen($replacement);
        }

        return $template;
    }

    /**
     * Match nested tags without interpreting delimiters inside attribute values.
     */
    private function findComponentClose(string $template, string $name, int $offset): array
    {
        $stack = [$name];

        while (preg_match(self::TAG_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $matches[0][1];
            $tagEnd = $this->findOpeningTagEnd($template, $start + strlen($matches[0][0]));

            if ($tagEnd === false) {
                break;
            }

            $offset = $tagEnd + 1;

            if (!str_starts_with($matches['name'][0], 'x-')) {
                continue;
            }

            $tagName = substr($matches['name'][0], 2);
            $closing = $matches['closing'][0] === '/';
            $selfClosing = str_ends_with(rtrim(substr($template, $start, $tagEnd - $start)), '/');

            if ($closing) {
                if ($tagName !== array_pop($stack)) {
                    throw new InvalidArgumentException("Mismatched closing tag for component [{$tagName}].");
                }

                if ($stack === []) {
                    return [$start, $tagEnd + 1];
                }
            } elseif (!$selfClosing) {
                $stack[] = $tagName;
            }
        }

        throw new InvalidArgumentException("Missing closing tag for component [{$name}].");
    }


    /**
     * Find the end of an opening tag, properly handling quotes and nested structures
     */
    private function findOpeningTagEnd(string $template, int $startPos): int|false
    {
        $length = strlen($template);
        $quote = null;

        for ($i = $startPos; $i < $length; $i++) {
            $char = $template[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i;
            }
        }

        return false;
    }

    /**
     * Capture rendered slot markup in the caller's scope, preserving user escaping.
     */
    private function processSlotContent(string $content): string
    {
        $content = trim($content);

        if (!preg_match('/<\\?|\\{\\{|\\{!!|@[a-zA-Z@]|__BLADE_RAW_/', $content)) {
            return var_export($content, true);
        }

        return '(function (array $__bladeSlotContext) { extract($__bladeSlotContext, EXTR_SKIP); '
            . 'ob_start(); ?>' . $content . '<?php return ob_get_clean(); })(get_defined_vars())';
    }

    /**
     * Parse attributes from x-component tag
     */
    private function parseXComponentAttributes(string $attributesString): array
    {
        if (empty(trim($attributesString))) {
            return [];
        }

        $attributes = [];
        $dynamicAttributes = [];
        $attributesString = trim($attributesString);
        $length = strlen($attributesString);
        $i = 0;

        while ($i < $length) {
            // Skip whitespace
            while ($i < $length && ctype_space($attributesString[$i])) {
                $i++;
            }

            if ($i >= $length) {
                break;
            }

            // Check for dynamic attribute prefix (:)
            $isDynamic = false;
            if ($attributesString[$i] === ':') {
                $isDynamic = ($attributesString[$i + 1] ?? '') !== ':';
                $i++;
            }

            // Parse attribute name (including colons for x-on:event, x-bind:attr, etc.)
            $nameStart = $i;
            while ($i < $length && (ctype_alnum($attributesString[$i]) || in_array($attributesString[$i], ['-', '_', '$', ':', '.', '@']))) {
                $i++;
            }

            if ($i === $nameStart) {
                $i++;
                continue;
            }

            $name = substr($attributesString, $nameStart, $i - $nameStart);

            // Skip empty names
            if (empty($name)) {
                continue;
            }

            if ($name[0] === '$') {
                $name = substr($name, 1);
            }

            if ($isDynamic) {
                $dynamicAttributes[$name] = true;
            } else {
                unset($dynamicAttributes[$name]);
            }

            // Skip whitespace after name
            while ($i < $length && ctype_space($attributesString[$i])) {
                $i++;
            }

            // Check for equals sign
            if ($i < $length && $attributesString[$i] === '=') {
                $i++; // Skip =

                // Skip whitespace after =
                while ($i < $length && ctype_space($attributesString[$i])) {
                    $i++;
                }

                // Parse value
                $value = $this->parseAttributeValue($attributesString, $i);

                if ($isDynamic && trim($value) === '') {
                    throw new InvalidArgumentException("Missing expression for bound component attribute [{$name}].");
                }

                $attributes[$name] = $value;
            } else {
                // Boolean attribute
                $attributes[$name] = $isDynamic ? "\$$name" : true;
            }
        }

        $attributes['__dynamic__'] = $dynamicAttributes;
        return $attributes;
    }

    /**
     * Parse attribute value handling quotes, arrays, functions, etc.
     */
    private function parseAttributeValue(string $attributesString, int &$position): string
    {
        $length = strlen($attributesString);
        $i = $position;

        if ($i >= $length) {
            return '';
        }

        $char = $attributesString[$i];

        // Handle quoted strings
        if ($char === '"' || $char === "'") {
            $quote = $char;
            $i++; // Skip opening quote
            $valueStart = $i;
            while ($i < $length) {
                if ($attributesString[$i] === $quote) {
                    $value = substr($attributesString, $valueStart, $i - $valueStart);
                    $position = $i + 1;

                    return $value;
                }

                $i++;
            }

            $value = substr($attributesString, $valueStart);
            $position = $length;
            return $value;
        }

        // Handle unquoted values
        $valueStart = $i;
        $depth = 0;
        $inString = false;
        $stringChar = null;
        $escaped = false;

        while ($i < $length) {
            $currentChar = $attributesString[$i];

            if (!$escaped && ($currentChar === '"' || $currentChar === "'")) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $currentChar;
                } elseif ($currentChar === $stringChar) {
                    $inString = false;
                    $stringChar = null;
                }
            }

            if ($currentChar === '\\' && !$escaped) {
                $escaped = true;
                $i++;
                continue;
            }
            $escaped = false;

            if (!$inString) {
                if (in_array($currentChar, ['(', '[', '{'])) {
                    $depth++;
                } elseif (in_array($currentChar, [')', ']', '}'])) {
                    $depth--;
                } elseif ($depth === 0 && ctype_space($currentChar)) {
                    break;
                }
            }

            $i++;
        }

        $value = substr($attributesString, $valueStart, $i - $valueStart);
        $position = $i;
        return trim($value);
    }

    /**
     * Check if position is at the start of next attribute
     */
    private function isStartOfNextAttribute(string $attributesString, int $position): bool
    {
        $length = strlen($attributesString);

        if ($position >= $length) {
            return true;
        }

        $char = $attributesString[$position];

        // Check for dynamic attribute prefix (:)
        if ($char === ':') {
            $position++;
            if ($position >= $length) {
                return false;
            }
            $char = $attributesString[$position];
        }

        // Check if it's a valid attribute name start (including @ for Alpine.js shorthand)
        if (ctype_alpha($char) || $char === '_' || $char === '$' || $char === '@') {
            // Look for attribute name pattern followed by = or space/end
            $nameEnd = $position;
            while (
                $nameEnd < $length &&
                (ctype_alnum($attributesString[$nameEnd]) ||
                    in_array($attributesString[$nameEnd], ['-', '_', '$', ':', '.', '@']))
            ) {
                $nameEnd++;
            }

            // Skip whitespace after name
            while ($nameEnd < $length && ctype_space($attributesString[$nameEnd])) {
                $nameEnd++;
            }

            // It's an attribute if followed by = or end of string or another attribute
            return $nameEnd >= $length ||
                $attributesString[$nameEnd] === '=' ||
                $this->isStartOfNextAttribute($attributesString, $nameEnd);
        }

        return false;
    }

    /**
     * Build PHP array string from attributes
     */
    private function buildAttributesArray(array $attributes, ?string $slot = null): string
    {
        $dynamicAttributes = $attributes['__dynamic__'] ?? [];
        unset($attributes['__dynamic__']);

        $pairs = [];

        foreach ($attributes as $key => $value) {
            $keyExpression = var_export($key, true);

            if (isset($dynamicAttributes[$key]) && !str_starts_with($key, ':')) {
                $valueExpression = "($value)";
            } elseif (is_string($value)) {
                $valueExpression = $this->compileAttributeEchos($value);
            } else {
                $valueExpression = var_export($value, true);
            }

            $pairs[] = "$keyExpression => $valueExpression";
        }

        if ($slot !== null) {
            $pairs[] = "'slot' => " . $this->processSlotContent($slot);
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    /**
     * Attribute echoes are string concatenations, never PHP tags inside a string.
     */
    private function compileAttributeEchos(string $value): string
    {
        if (!str_contains($value, '{{') && !str_contains($value, '{!!')) {
            return var_export(strtr($value, $this->literalBlocks), true);
        }

        $parts = preg_split('/(@?\\{!!.*?!!\\}|@?\\{\\{.*?\\}\\})/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $expressions = [];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $expressions[] = var_export(strtr($part, $this->literalBlocks), true);
            } elseif (str_starts_with($part, '@')) {
                $expressions[] = var_export(substr($part, 1), true);
            } elseif (str_starts_with($part, '{!!')) {
                $expressions[] = '(' . trim(substr($part, 3, -3)) . ')';
            } else {
                $expressions[] = 'e(' . trim(substr($part, 2, -2)) . ')';
            }
        }

        return implode(' . ', $expressions);
    }

    /**
     * Compile @include directives
     *
     * @param string $template
     * @return string
     */
    private function compileIncludes(string $template): string
    {
        $directives = [
            'includeWhen' => function (string $expression): string {
                $args = $this->splitDirectiveArguments($expression, 3);
                $condition = $args[0] ?? 'false';
                $viewExpr = $args[1] ?? "''";
                $dataExpr = $args[2] ?? '[]';
                return "<?php if ({$condition}): echo \$this->include({$viewExpr}, {$dataExpr}); endif; ?>";
            },
            'includeIf' => function (string $expression): string {
                $args = $this->splitDirectiveArguments($expression, 2);
                $viewExpr = $args[0] ?? "''";
                $dataExpr = $args[1] ?? '[]';
                return "<?php if (\$this->templateExists({$viewExpr})): echo \$this->include({$viewExpr}, {$dataExpr}); endif; ?>";
            },
            'include' => function (string $expression): string {
                $args = $this->splitDirectiveArguments($expression, 2);
                $viewExpr = $args[0] ?? "''";
                $dataExpr = $args[1] ?? '[]';
                return "<?= \$this->include({$viewExpr}, {$dataExpr}); ?>";
            },
        ];

        foreach ($directives as $directive => $handler) {
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

    /**
     * Split top-level directive arguments respecting nested parentheses and quotes.
     */
    private function splitDirectiveArguments(string $expression, int $limit = PHP_INT_MAX): array
    {
        $expression = strtr($expression, $this->expressionBlocks);
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

    /**
     * Compile @use directive
     * 
     * @param string $template
     * @return string
     */
    private function compileUseDirective(string $template): string
    {
        // Pattern to match @use('namespace\Class') or @use('namespace\Class', 'alias')
        return preg_replace_callback(
            '/\@use\s*\(\s*([^,)]+)(?:\s*,\s*([^,)]+))?\s*\)/s',
            function ($matches) {
                $arguments = $this->splitDirectiveArguments($matches[1], 2);
                $classExpr = trim($arguments[0]);
                $aliasExpr = isset($arguments[1]) ? trim($arguments[1]) : null;

                // Remove quotes from class name for use statement
                $className = trim($classExpr, '\'"');

                if ($aliasExpr) {
                    // Remove quotes from alias
                    $alias = trim($aliasExpr, '\'"');
                    return "<?php use {$className} as {$alias}; ?>";
                } else {
                    return "<?php use {$className}; ?>";
                }
            },
            $template
        );
    }

    /**
     * Compile echo statements {{ }} and {!! !!}
     * 
     * @param string $template
     * @return string
     */
    private function compileEchos(string $template): string
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

    /**
     * Compile directives (@if, @foreach, etc.)
     * 
     * @param string $template
     * @return string
     */
    private function compileDirectives(string $template): string
    {
        // Define directive configurations to reduce duplication
        $conditionalDirectives = [
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

        $loopDirectives = [
            'foreach' => ['open' => 'foreach(%s):', 'close' => 'endforeach;'],
            'for' => ['open' => 'for(%s):', 'close' => 'endfor;'],
            'while' => ['open' => 'while(%s):', 'close' => 'endwhile;']
        ];

        $outputDirectives = [
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

        $singleLineDirectives = [
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

        // Compile conditional directives
        foreach ($conditionalDirectives as $directive => $config) {
            if (!str_contains($template, '@' . $directive) && !str_contains($template, '@end' . $directive)) {
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
        foreach ($loopDirectives as $directive => $config) {
            if (!str_contains($template, '@' . $directive) && !str_contains($template, '@end' . $directive)) {
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
        foreach ($outputDirectives as $directive => $phpCode) {
            if (str_contains($template, '@' . $directive)) {
                $template = $this->compileOutsidePhp($template, fn($source) => $this->compileDirectiveWithExpression(
                    $source,
                    $directive,
                    $phpCode
                ));
            }
        }

        // Compile custom directives
        $template = $this->compileCustomDirectives($template);

        //  Compile single-line directives
        foreach ($singleLineDirectives as $directive => $phpCode) {
            if (str_contains($template, '@' . $directive)) {
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
    private function compileDirectiveWithExpression(string $template, string $directive, string $openTemplate, ?string $closeTemplate = null): string
    {
        // Find all occurrences of @directive(
        $pattern = '/\@' . preg_quote($directive, '/') . '\s*\(/';
        $offset = 0;

        while (preg_match($pattern, $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $parenStart = $matchStart + strlen($matches[0][0]) - 1; // Position of opening (

            // Find the matching closing parenthesis
            $expression = $this->extractBalancedExpression($template, $parenStart);

            if ($expression === false) {
                throw new InvalidArgumentException("Unbalanced parentheses in @{$directive} directive");
            }

            // Build the replacement
            $replacement = sprintf('<?php %s ?>', $this->sprintfRepeat($openTemplate, $expression));

            // Replace the directive with compiled PHP
            $directiveLength = $parenStart - $matchStart + strlen($expression) + 2; // +2 for opening and closing parentheses
            $template = substr_replace($template, $replacement, $matchStart, $directiveLength);

            // Update offset for next search
            $offset = $matchStart + strlen($replacement);
        }

        // Handle closing directive if it exists
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
    private function sprintfRepeat(string $template, string $expression): string
    {
        // Count number of %s in template
        $count = substr_count($template, '%s');

        // Create an array with the expression repeated
        $args = array_fill(0, $count, $expression);

        // Use sprintf to format the template
        return sprintf($template, ...$args);
    }

    /**
     * Extract expression between balanced parentheses
     */
    private function extractBalancedExpression(string $template, int $startPos): string|false
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
     * Compile single-line directives like @break, @continue, etc.
     * 
     * @param string $template
     * @param string $directive
     * @param string $phpCode
     * @return string
     */
    public function compileSingleLineDirective(string $template, string $directive, string $phpCode): string
    {
        $template = preg_replace('/\@' . preg_quote($directive, '/') . '\b/', $phpCode, $template);
        return $template;
    }

    /**
     * Compile custom directives
     */
    private function compileCustomDirectives(string $template): string
    {
        foreach ($this->customDirectives as $directive => $callback) {
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

                    $replacement = $callback(strtr($expression, $this->expressionBlocks));
                    $source = substr_replace($source, $replacement, $start, $length);
                    $offset = $start + strlen($replacement);
                }

                return $source;
            });
        }

        return $template;
    }

    /**
     * Compile @php blocks
     * 
     * @param string $template
     * @return string
     */
    private function compilePhpBlocks(string $template): string
    {
        return $this->compileDirectiveWithExpression($template, 'php', '%s;');
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
