<?php

namespace Spark\View;

use InvalidArgumentException;
use Spark\View\Contracts\BladeCompilerContract;
use function count;
use function in_array;
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

    public function __construct(string $cachePath)
    {
        $this->cachePath = dir_path($cachePath);

        // Ensure the cache directory exists and is writable
        fm()->ensureDirectoryWritable($this->cachePath);
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
        // Remove Blade comments first
        $template = $this->compileComments($template);

        // Preserve verbatim blocks (add this line)
        $template = $this->preserveVerbatimBlocks($template);

        // Preserve raw PHP blocks
        $template = $this->preserveRawBlocks($template);

        // Compile @use directive
        $template = $this->compileUseDirective($template);

        // Compile structural directives
        $template = $this->compileExtends($template);
        $template = $this->compileSections($template);
        $template = $this->compileYields($template);
        $template = $this->compileIncludes($template);
        $template = $this->compileXComponents($template);

        // Compile control flow directives
        $template = $this->compileDirectives($template);

        // Compile PHP blocks
        $template = $this->compilePhpBlocks($template);

        // Compile echo statements last to avoid conflicts
        $template = $this->compileEchos($template);

        // Restore raw PHP blocks
        $template = $this->restoreRawBlocks($template);

        return $template;
    }

    /**
     * Preserve verbatim blocks
     * 
     * @param string $template
     * @return string
     */
    private function preserveVerbatimBlocks(string $template): string
    {
        return preg_replace_callback('/\@verbatim(.*?)\@endverbatim/s', function ($matches) {
            $key = '__VERBATIM_BLOCK_' . count($this->rawBlocks) . '__';
            $content = $matches[1];
            $this->rawBlocks[$key] = $content; // Store as-is, no PHP tags
            return $key;
        }, $template);
    }

    /**
     * Preserve raw PHP blocks
     * 
     * @param string $template
     * @return string
     */
    private function preserveRawBlocks(string $template): string
    {
        return preg_replace_callback('/\@php(.*?)\@endphp/s', function ($matches) {
            $key = '__RAW_BLOCK_' . count($this->rawBlocks) . '__';
            $content = trim($matches[1]);
            $this->rawBlocks[$key] = "<?php" . ($content ? "\n    $content\n" : "") . "?>";
            return $key;
        }, $template);
    }

    /**
     * Restore raw PHP blocks
     * 
     * @param string $template
     * @return string
     */
    private function restoreRawBlocks(string $template): string
    {
        foreach ($this->rawBlocks as $key => $block) {
            $template = str_replace($key, $block, $template);
        }
        $this->rawBlocks = [];
        return $template;
    }

    /**
     * Compile @extends directive
     * 
     * @param string $template
     * @return string
     */
    private function compileExtends(string $template): string
    {
        return preg_replace('/\@extends\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?php $this->setExtends(\'$1\'); ?>', $template);
    }

    /**
     * Compile @section and @endsection directives
     */
    private function compileSections(string $template): string
    {
        // @section('name')
        $template = preg_replace('/\@section\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?php $this->startSection(\'$1\'); ?>', $template);

        // @section('name', expression) - inline section
        $template = preg_replace_callback('/\@section\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+?)\s*\)/s', function ($matches) {
            $sectionName = $matches[1];
            $expression = trim($matches[2]);
            return "<?php \$this->startSection('$sectionName'); echo $expression; \$this->endSection(); ?>";
        }, $template);

        // @endsection
        $template = preg_replace('/\@endsection/', '<?php $this->endSection(); ?>', $template);

        // @stop (alias for @endsection)
        $template = preg_replace('/\@stop/', '<?php $this->endSection(); ?>', $template);

        // @show (end section and immediately yield it)
        $template = preg_replace('/\@show/', '<?php $this->endSection(); echo $this->yieldSection($this->getCurrentSection()); ?>', $template);

        return $template;
    }

    /**
     * Compile @yield directives
     */
    private function compileYields(string $template): string
    {
        // @yield('section', 'default') - with string default
        $template = preg_replace('/\@yield\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*[\'"]([^\'"]*)[\'"])?\s*\)/', '<?= $this->yieldSection(\'$1\', \'$2\'); ?>', $template);

        // @yield('section', $variable) - with variable default
        $template = preg_replace('/\@yield\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*([^,\)\'\"]+))?\s*\)/', '<?= $this->yieldSection(\'$1\', isset($2) ? $2 : \'\'); ?>', $template);

        // @yield('section') - no default
        $template = preg_replace('/\@yield\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?= $this->yieldSection(\'$1\', \'\'); ?>', $template);

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
        // Process components recursively from innermost to outermost
        $previousTemplate = '';
        while ($template !== $previousTemplate) {
            $previousTemplate = $template;

            // Handle self-closing x-components first: <x-component />
            $template = $this->compileSelfClosingXComponents($template);

            // Handle x-components with content: <x-component>content</x-component>
            $template = $this->compileXComponentsWithContent($template);
        }

        return $template;
    }

    /**
     * Compile self-closing x-components: <x-component />
     */
    private function compileSelfClosingXComponents(string $template): string
    {
        $pattern = '/<x-([a-zA-Z0-9\-_.:]+)([^>]*?)\/>/';

        return preg_replace_callback($pattern, function ($matches) {
            $componentName = $matches[1];
            $attributesString = trim($matches[2]);

            // Parse attributes
            $attributes = $this->parseXComponentAttributes($attributesString);
            $attributesArray = $this->buildAttributesArray($attributes);

            return "<?= \$this->component('{$componentName}', {$attributesArray}); ?>";
        }, $template);
    }

    /**
     * Compile x-components with content: <x-component>content</x-component>
     */
    private function compileXComponentsWithContent(string $template): string
    {
        $offset = 0;
        $length = strlen($template);

        while ($offset < $length) {
            // Find the start of an x-component
            $pos = strpos($template, '<x-', $offset);
            if ($pos === false) {
                break;
            }

            // Find the component name
            $nameStart = $pos + 3; // Skip '<x-'
            $nameEnd = $nameStart;
            while ($nameEnd < $length && (ctype_alnum($template[$nameEnd]) || in_array($template[$nameEnd], ['-', '_', '.', ':']))) {
                $nameEnd++;
            }

            if ($nameEnd === $nameStart) {
                $offset = $pos + 1;
                continue;
            }

            $componentName = substr($template, $nameStart, $nameEnd - $nameStart);

            // Now manually find the end of the opening tag by tracking quotes and brackets
            $tagEnd = $this->findOpeningTagEnd($template, $nameEnd);
            if ($tagEnd === false) {
                $offset = $pos + 1;
                continue;
            }

            // Extract attributes string
            $attributesStart = $nameEnd;
            while ($attributesStart < $tagEnd && ctype_space($template[$attributesStart])) {
                $attributesStart++;
            }

            $attributesEnd = $tagEnd;
            while ($attributesEnd > $attributesStart && ctype_space($template[$attributesEnd - 1])) {
                $attributesEnd--;
            }

            $attributesString = '';
            if ($attributesEnd > $attributesStart) {
                $attributesString = substr($template, $attributesStart, $attributesEnd - $attributesStart);
            }

            // Check if it's self-closing
            if ($tagEnd > 0 && $template[$tagEnd - 1] === '/') {
                // Self-closing tag - handle it
                $attributes = $this->parseXComponentAttributes($attributesString);
                $attributesArray = $this->buildAttributesArray($attributes);
                $replacement = "<?= \$this->component('{$componentName}', {$attributesArray}); ?>";

                $template = substr_replace($template, $replacement, $pos, $tagEnd - $pos + 1);
                $offset = $pos + strlen($replacement);
                continue;
            }

            // Find the matching closing tag
            $closeTag = "</x-{$componentName}>";
            $contentStart = $tagEnd + 1;
            $depth = 1;
            $searchPos = $contentStart;

            while ($depth > 0 && $searchPos < $length) {
                $nextOpen = strpos($template, "<x-{$componentName}", $searchPos);
                $nextClose = strpos($template, $closeTag, $searchPos);

                if ($nextClose === false) {
                    break;
                }

                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    // Make sure it's actually a complete opening tag, not just text containing the string
                    $checkPos = $nextOpen + strlen("<x-{$componentName}");
                    if ($checkPos < $length && (ctype_space($template[$checkPos]) || $template[$checkPos] === '>' || $template[$checkPos] === '/')) {
                        $depth++;
                        $searchPos = $nextOpen + strlen("<x-{$componentName}");
                    } else {
                        $searchPos = $nextOpen + 1;
                    }
                } else {
                    $depth--;
                    if ($depth === 0) {
                        // Found our matching closing tag
                        $slotContent = substr($template, $contentStart, $nextClose - $contentStart);

                        // Parse attributes
                        $attributes = $this->parseXComponentAttributes($attributesString);

                        // Process slot content
                        $processedSlotContent = $this->processSlotContent($slotContent);

                        // Add slot content to attributes if not empty
                        if (!empty(trim($processedSlotContent))) {
                            $attributes['slot'] = $processedSlotContent;
                        }

                        $attributesArray = $this->buildAttributesArray($attributes);
                        $replacement = "<?= \$this->component('{$componentName}', {$attributesArray}); ?>";

                        // Replace the entire component
                        $totalLength = $nextClose + strlen($closeTag) - $pos;
                        $template = substr_replace($template, $replacement, $pos, $totalLength);

                        $offset = $pos + strlen($replacement);
                        break;
                    } else {
                        $searchPos = $nextClose + strlen($closeTag);
                    }
                }
            }

            if ($depth > 0) {
                // No matching closing tag found
                $offset = $pos + 1;
            }
        }

        return $template;
    }


    /**
     * Find the end of an opening tag, properly handling quotes and nested structures
     */
    private function findOpeningTagEnd(string $template, int $startPos): int|false
    {
        $length = strlen($template);
        $inQuote = false;
        $quoteChar = null;
        $depth = 0;
        $escaped = false;

        for ($i = $startPos; $i < $length; $i++) {
            $char = $template[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if (!$inQuote && ($char === '"' || $char === "'")) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar) {
                $inQuote = false;
                $quoteChar = null;
            } elseif (!$inQuote) {
                if (in_array($char, ['(', '[', '{'])) {
                    $depth++;
                } elseif (in_array($char, [')', ']', '}'])) {
                    $depth--;
                } elseif ($char === '>' && $depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * Process slot content, handling nested components and PHP expressions
     */
    private function processSlotContent(string $content): string
    {
        // Trim the content
        $content = trim($content);

        if (empty($content)) {
            return '';
        }

        // For simple text content, use string directly for better performance
        if (!$this->containsDynamicContent($content)) {
            return $this->escapeSlotContent($content);
        }

        // If it contains dynamic content, use ob_start() buffering
        return "function() { ob_start(); ?>{$content}<?php return ob_get_clean(); }";
    }

    /**
     * Check if content contains dynamic content that needs ob_start() buffering
     */
    private function containsDynamicContent(string $content): bool
    {
        // Check for PHP tags
        if (strpos($content, '<?') !== false) {
            return true;
        }

        // Check for template expressions {{ }} or {!! !!}
        if (preg_match('/\{\{.*?\}\}|\{!!.*?!!\}/', $content)) {
            return true;
        }

        // Check for template directives
        if (preg_match('/@[a-zA-Z]+/', $content)) {
            return true;
        }

        // Check for x-components (both self-closing and with content)
        if (preg_match('/<x-[a-zA-Z0-9\-_.:]+/', $content)) {
            return true;
        }

        // Check for HTML tags (any tag suggests potential complexity)
        if (preg_match('/<[a-zA-Z][^>]*>/', $content)) {
            return true;
        }

        return false;
    }

    /**
     * Escape slot content for safe inclusion in PHP string
     */
    private function escapeSlotContent(string $content): string
    {
        // Remove extra whitespace while preserving structure
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        // Escape single quotes and backslashes for PHP string
        $escaped = addslashes($content);

        return $escaped;
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

            if ($i >= $length)
                break;

            // Check for dynamic attribute prefix (:)
            $isDynamic = false;
            if ($attributesString[$i] === ':') {
                $isDynamic = true;
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
            $depth = 0;
            $escaped = false;

            while ($i < $length) {
                $currentChar = $attributesString[$i];

                if ($escaped) {
                    $escaped = false;
                } elseif ($currentChar === '\\') {
                    $escaped = true;
                } else {
                    if (in_array($currentChar, ['(', '[', '{'])) {
                        $depth++;
                    } elseif (in_array($currentChar, [')', ']', '}'])) {
                        $depth--;
                    } elseif ($currentChar === $quote && $depth === 0) {
                        $value = substr($attributesString, $valueStart, $i - $valueStart);
                        $i++; // Skip closing quote
                        $position = $i;
                        return $value;
                    }
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
    private function buildAttributesArray(array $attributes): string
    {
        // Extract dynamic attribute info
        $dynamicAttributes = $attributes['__dynamic__'] ?? [];
        unset($attributes['__dynamic__']); // Remove from actual attributes

        if (empty($attributes)) {
            return '[]';
        }

        $pairs = [];
        foreach ($attributes as $key => $value) {
            $escapedKey = addslashes($key);
            $isDynamic = isset($dynamicAttributes[$key]);

            if ($value === true) {
                $pairs[] = "'{$escapedKey}' => true";
            } elseif ($value === false) {
                $pairs[] = "'{$escapedKey}' => false";
            } elseif ($key === 'slot') {
                // Special handling for slot content - always treat as closure
                if (is_string($value) && strpos($value, 'function()') === 0) {
                    // It's already a closure for dynamic content
                    $pairs[] = "'{$escapedKey}' => ({$value})()";
                } else {
                    // This shouldn't happen now since we always use ob_start, but keep for safety
                    $pairs[] = "'{$escapedKey}' => '{$value}'";
                }
            } elseif (is_string($value)) {
                if ($isDynamic && !str_starts_with($key, ':')) {
                    // Dynamic attribute - treat as PHP expression
                    $pairs[] = "'{$escapedKey}' => {$value}";
                } else {
                    // Static attribute - treat as string
                    $escapedValue = addslashes($value);
                    $pairs[] = "'{$escapedKey}' => '{$escapedValue}'";
                }
            } else {
                $pairs[] = "'{$escapedKey}' => {$value}";
            }
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    /**
     * Compile @include directives
     *
     * @param string $template
     * @return string
     */
    private function compileIncludes(string $template): string
    {
        // Compile @includeWhen directive
        $template = preg_replace_callback(
            '/@includeWhen\(\s*(.+?)\s*,\s*(.+?)(?:\s*,\s*(.+?))?\s*\)/s',
            function ($matches) {
                $condition = $matches[1];
                $viewExpr = $matches[2];
                $dataExpr = isset($matches[3]) && trim($matches[3]) !== '' ? $matches[3] : '[]';

                return "<?php if ({$condition}): echo \$this->include({$viewExpr}, {$dataExpr}); endif; ?>";
            },
            $template
        );

        // Compile @includeIf directive
        $template = preg_replace_callback(
            '/@includeIf\(\s*(.+?)(?:\s*,\s*(.+?))?\s*\)/s',
            function ($matches) {
                $viewExpr = $matches[1];
                $dataExpr = isset($matches[2]) && trim($matches[2]) !== '' ? $matches[2] : '[]';

                return "<?php if (\$this->templateExists({$viewExpr})): echo \$this->include({$viewExpr}, {$dataExpr}); endif; ?>";
            },
            $template
        );

        // Compile @include directive
        return preg_replace_callback(
            '/@include\(\s*(.+?)(?:\s*,\s*(.+?))?\s*\)/s',
            function ($matches) {
                $viewExpr = $matches[1];
                $dataExpr = isset($matches[2]) && trim($matches[2]) !== '' ? $matches[2] : '[]';

                return "<?= \$this->include({$viewExpr}, {$dataExpr}); ?>";
            },
            $template
        );
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
                $classExpr = trim($matches[1]);
                $aliasExpr = isset($matches[2]) ? trim($matches[2]) : null;

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
     * Compile Blade comments {{-- --}}
     * Remove them completely from the template
     * 
     * @param string $template
     * @return string
     */
    private function compileComments(string $template): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $template);
    }


    /**
     * Compile echo statements {{ }} and {!! !!}
     * 
     * @param string $template
     * @return string
     */
    private function compileEchos(string $template): string
    {
        // Raw echo {!! !!} - don't escape HTML
        $template = preg_replace('/\{\!\!\s*(.+?)\s*\!\!\}/s', '<?= $1; ?>', $template);

        // Escaped echo {{ }} - escape HTML for security
        $template = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', function ($matches) {
            $expression = trim($matches[1]);
            return "<?= e($expression); ?>";
        }, $template);

        return $template;
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
            'method' => 'echo method(%s);',
            'checked' => "echo (%s) ? 'checked' : '';",
            'disabled' => "echo (%s) ? 'disabled' : '';",
            'selected' => "echo (%s) ? 'selected' : '';",
            'readonly' => "echo (%s) ? 'readonly' : '';",
            'required' => "echo (%s) ? 'required' : '';",
            'style' => "echo \$this->compileAttributes(['style' => %s]);",
            'class' => "echo \$this->compileAttributes(['class' => %s]);",
            'attributes' => "echo \$this->compileAttributes(%s);",
            'errors' => "if(errors()->any() && errors()->has('%s')): foreach(errors()->get('%s') as \$message):",
            'error' => "if(errors()->any() && errors()->has('%s')): \$message = errors()->first('%s');",
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
            $template = $this->compileDirectiveWithExpression($template, $directive, $config['open'], $config['close']);
        }

        // Compile loop directives
        foreach ($loopDirectives as $directive => $config) {
            $template = $this->compileDirectiveWithExpression($template, $directive, $config['open'], $config['close']);
        }

        // Compile output directives
        foreach ($outputDirectives as $directive => $phpCode) {
            $template = $this->compileDirectiveWithExpression($template, $directive, $phpCode);
        }

        // Compile custom directives
        $template = $this->compileCustomDirectives($template);

        //  Compile single-line directives
        foreach ($singleLineDirectives as $directive => $phpCode) {
            $template = $this->compileSingleLineDirective($template, $directive, $phpCode);
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
            $template = preg_replace(
                '/\@' . preg_quote($closeDirective, '/') . '\b/',
                sprintf('<?php %s ?>', $closeTemplate),
                $template
            );
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
            $pattern = '/\@' . preg_quote($directive, '/') . '\s*\(/';
            $offset = 0;

            while (preg_match($pattern, $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $matchStart = $matches[0][1];
                $parenStart = $matchStart + strlen($matches[0][0]) - 1;

                $expression = $this->extractBalancedExpression($template, $parenStart);

                if ($expression === false) {
                    throw new InvalidArgumentException("Unbalanced parentheses in @{$directive} directive");
                }

                $replacement = $callback($expression);
                $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
                $template = substr_replace($template, $replacement, $matchStart, $directiveLength);

                $offset = $matchStart + strlen($replacement);
            }
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
        // Only match single-line @php() directives
        return preg_replace('/\@php\s*\(\s*(.+?)\s*\)(?!\s*@endphp)/', '<?php $1; ?>', $template);
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
