<?php

namespace Spark\View\Compilers;

use InvalidArgumentException;
use Spark\View\BladeCompiler;
use Spark\View\Compilers\Concerns\ParsesBladeSyntax;
use function array_pop;
use function ctype_alnum;
use function ctype_alpha;
use function ctype_space;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function preg_split;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtr;
use function substr;
use function trim;
use function var_export;
use const PREG_OFFSET_CAPTURE;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Class ComponentCompiler
 * 
 * Compiles <x-component-name> tags, attributes, and slots.
 */
class ComponentCompiler
{
    use ParsesBladeSyntax;

    private const TAG_PATTERN = '/<(?<closing>\/)?(?<name>[a-zA-Z][a-zA-Z0-9\-_.:]*)(?=[\s\/>])/';

    public function __construct(private BladeCompiler $compiler)
    {
    }

    /**
     * Compile X-Components (<x-component-name>)
     */
    public function compile(string $template): string
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

            if ($name === 'slot' || str_starts_with($name, 'slot:')) {
                throw new InvalidArgumentException("Unexpected slot tag [<{$tagName}>] outside of component.");
            }

            $attributeSource = rtrim(substr($template, $nameEnd, $tagEnd - $nameEnd));
            $selfClosing = str_ends_with($attributeSource, '/');

            if ($selfClosing) {
                $attributeSource = substr($attributeSource, 0, -1);
                $end = $tagEnd + 1;
                $slot = null;
                $namedSlots = [];
            } else {
                [$closeStart, $end] = $this->findComponentClose($template, $name, $tagEnd + 1);
                $rawBody = substr($template, $tagEnd + 1, $closeStart - $tagEnd - 1);
                [$slot, $namedSlots] = $this->extractSlots($rawBody);
            }

            $attributes = $this->parseXComponentAttributes($attributeSource);
            $attributesArray = $this->buildAttributesArray($attributes, $slot, $namedSlots);
            $replacement = "<?= \$this->component('{$name}', {$attributesArray}); ?>";
            $template = substr_replace($template, $replacement, $start, $end - $start);
            $offset = $start + strlen($replacement);
        }

        return $template;
    }

    /**
     * Match nested tags without interpreting delimiters inside attribute values.
     */
    public function findComponentClose(string $template, string $name, int $offset): array
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
                $expected = array_pop($stack);
                if ($tagName !== $expected && !($tagName === 'slot' && str_starts_with($expected, 'slot:'))) {
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
    public function findOpeningTagEnd(string $template, int $startPos): int|false
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
    public function processSlotContent(string $content, array $attributes = []): string
    {
        $content = trim($content);
        $attributesExport = $this->buildAttributesArray($attributes);

        if (!preg_match('/<\\?|\\{\\{|\\{!!|@[a-zA-Z@]|__BLADE_RAW_/', $content)) {
            $exportContent = var_export($content, true);
            return "new \\Spark\\View\\ComponentSlot({$exportContent}, {$attributesExport})";
        }

        return '(function (array $__bladeSlotContext) { extract($__bladeSlotContext, EXTR_SKIP); '
            . 'ob_start(); ?>' . $content . '<?php return new \\Spark\\View\\ComponentSlot(ob_get_clean(), ' . $attributesExport . '); })(get_defined_vars())';
    }

    /**
     * Parse attributes from x-component tag
     */
    public function parseXComponentAttributes(string $attributesString): array
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
    public function parseAttributeValue(string $attributesString, int &$position): string
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
    public function isStartOfNextAttribute(string $attributesString, int $position): bool
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
    public function buildAttributesArray(array $attributes, ?string $slot = null, array $namedSlots = []): string
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

        if ($namedSlots !== []) {
            $attributesArray = '[' . implode(', ', $pairs) . ']';
            return '(function (array $__bladeComponentContext, array $__bladeComponentAttributes) { '
                . 'extract($__bladeComponentContext, EXTR_SKIP); $__bladeNamedSlots = []; ob_start(); ?>'
                . trim($slot ?? '')
                . '<?php $__bladeComponentAttributes["slot"] = new \\Spark\\View\\ComponentSlot(ob_get_clean()); '
                . 'return [...$__bladeComponentAttributes, ...$__bladeNamedSlots]; })(get_defined_vars(), '
                . $attributesArray . ')';
        }

        if ($slot !== null) {
            $pairs[] = "'slot' => " . $this->processSlotContent($slot);
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    /**
     * Extract top-level <x-slot> tags and the remaining default slot.
     *
     * @return array{0: string|null, 1: array<string, array{content: string, attributes: array}>}
     */
    public function extractSlots(string $body): array
    {
        if (!str_contains($body, '<x-slot')) {
            return [$this->compile($body), []];
        }

        $namedSlots = [];
        $defaultSlot = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            if (!preg_match(self::TAG_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $defaultSlot .= substr($body, $offset);
                break;
            }

            $start = $matches[0][1];
            $tagName = $matches['name'][0];
            $nameEnd = $start + strlen($matches[0][0]);
            $tagEnd = $this->findOpeningTagEnd($body, $nameEnd);

            if ($tagEnd === false) {
                $defaultSlot .= substr($body, $offset);
                break;
            }

            if (!str_starts_with($tagName, 'x-')) {
                $defaultSlot .= substr($body, $offset, $tagEnd + 1 - $offset);
                $offset = $tagEnd + 1;
                continue;
            }

            $isSlot = ($tagName === 'x-slot' || str_starts_with($tagName, 'x-slot:'));

            if (!$isSlot) {
                $compName = substr($tagName, 2);
                $isClosing = $matches['closing'][0] === '/';
                $attributeSource = rtrim(substr($body, $nameEnd, $tagEnd - $nameEnd));
                $selfClosing = str_ends_with($attributeSource, '/');

                if (!$isClosing && !$selfClosing) {
                    [, $innerEnd] = $this->findComponentClose($body, $compName, $tagEnd + 1);
                    $defaultSlot .= substr($body, $offset, $innerEnd - $offset);
                    $offset = $innerEnd;
                } else {
                    $defaultSlot .= substr($body, $offset, ($tagEnd + 1) - $offset);
                    $offset = $tagEnd + 1;
                }
                continue;
            }

            $isClosing = $matches['closing'][0] === '/';
            if ($isClosing) {
                throw new InvalidArgumentException("Unexpected closing tag [</{$tagName}>] without opening slot.");
            }

            $defaultSlot .= substr($body, $offset, $start - $offset);

            $attributeSource = rtrim(substr($body, $nameEnd, $tagEnd - $nameEnd));
            $selfClosing = str_ends_with($attributeSource, '/');
            if ($selfClosing) {
                $attributeSource = substr($attributeSource, 0, -1);
            }

            $attributes = $this->parseXComponentAttributes($attributeSource);

            $slotName = str_starts_with($tagName, 'x-slot:') ? substr($tagName, 7) : null;
            if ($slotName === null || $slotName === '') {
                if (isset($attributes['name'])) {
                    if (!is_string($attributes['name']) || isset($attributes['__dynamic__']['name'])) {
                        throw new InvalidArgumentException('Slot name must be a static string.');
                    }
                    $slotName = $attributes['name'];
                    unset($attributes['name']);
                } else {
                    throw new InvalidArgumentException('Missing [name] attribute on <x-slot> tag.');
                }
            }

            if ($slotName === '') {
                throw new InvalidArgumentException('Slot name cannot be empty.');
            }

            $slotName = \Spark\Support\Str::camel($slotName);
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $slotName) || in_array($slotName, ['slot', 'attributes'], true)) {
                throw new InvalidArgumentException("Invalid slot name [{$slotName}].");
            }

            if ($selfClosing) {
                $slotContent = '';
                $offset = $tagEnd + 1;
            } else {
                [$slotCloseStart, $slotEnd] = $this->findSlotClose($body, $tagEnd + 1);
                $slotContent = substr($body, $tagEnd + 1, $slotCloseStart - $tagEnd - 1);
                $offset = $slotEnd;
            }

            $namedSlots[$slotName] = [
                'content' => $this->compile($slotContent),
                'attributes' => $attributes,
            ];
            $defaultSlot .= '<?php $__bladeNamedSlots[' . var_export($slotName, true) . '] = '
                . $this->processSlotContent($namedSlots[$slotName]['content'], $attributes) . '; ?>';
        }

        return [$this->compileOutsidePhp($defaultSlot, $this->compile(...)), $namedSlots];
    }

    /**
     * Find closing tag for an <x-slot> tag.
     */
    public function findSlotClose(string $body, int $offset): array
    {
        $stack = ['slot'];

        while (preg_match(self::TAG_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $matches[0][1];
            $tagEnd = $this->findOpeningTagEnd($body, $start + strlen($matches[0][0]));

            if ($tagEnd === false) {
                break;
            }

            $offset = $tagEnd + 1;

            if (!str_starts_with($matches['name'][0], 'x-')) {
                continue;
            }

            $tagName = substr($matches['name'][0], 2);
            $closing = $matches['closing'][0] === '/';
            $selfClosing = str_ends_with(rtrim(substr($body, $start, $tagEnd - $start)), '/');

            $isSlotTag = ($tagName === 'slot' || str_starts_with($tagName, 'slot:'));
            $normalizedName = $isSlotTag ? 'slot' : $tagName;

            if ($closing) {
                $expected = array_pop($stack);
                if ($expected !== $normalizedName) {
                    throw new InvalidArgumentException("Mismatched closing tag for slot [{$tagName}].");
                }

                if ($stack === []) {
                    return [$start, $tagEnd + 1];
                }
            } elseif (!$selfClosing) {
                $stack[] = $normalizedName;
            }
        }

        throw new InvalidArgumentException('Missing closing tag for <x-slot>.');
    }

    /**
     * Attribute echoes are string concatenations, never PHP tags inside a string.
     */
    public function compileAttributeEchos(string $value): string
    {
        $literalBlocks = $this->compiler->getLiteralBlocks();

        if (!str_contains($value, '{{') && !str_contains($value, '{!!')) {
            return var_export(strtr($value, $literalBlocks), true);
        }

        $parts = preg_split('/(@?\\{!!.*?!!\\}|@?\\{\\{.*?\\}\\})/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $expressions = [];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $expressions[] = var_export(strtr($part, $literalBlocks), true);
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
}
