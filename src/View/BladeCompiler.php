<?php

namespace Spark\View;

use Spark\View\Contracts\BladeCompilerContract;

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
     * Array of component aliases
     * 
     * @var array
     */
    private array $componentAliases = [];

    /**
     * Raw PHP blocks to preserve during compilation
     * 
     * @var array
     */
    private array $rawBlocks = [];

    public function __construct(string $cachePath)
    {
        $this->cachePath = rtrim($cachePath, '/');
        $this->ensureCacheDirectoryExists();
    }

    /**
     * Compile a template file
     * 
     * @param string $templatePath
     * @param string $compiledPath
     * @return void
     */
    public function compile(string $templatePath, string $compiledPath): void
    {
        $content = file_get_contents($templatePath);
        $compiled = $this->compileString($content);

        file_put_contents($compiledPath, $compiled);
    }

    /**
     * Compile template string
     * 
     * @param string $template
     * @return string
     */
    public function compileString(string $template): string
    {
        // Preserve raw PHP blocks
        $template = $this->preserveRawBlocks($template);

        // Compile different template features
        $template = $this->compileExtends($template);
        $template = $this->compileSections($template);
        $template = $this->compileYields($template);
        $template = $this->compileIncludes($template);
        $template = $this->compileComponents($template);
        $template = $this->compileEchos($template);
        $template = $this->compileDirectives($template);
        $template = $this->compilePhpBlocks($template);

        // Restore raw PHP blocks
        $template = $this->restoreRawBlocks($template);

        return $template;
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
            $this->rawBlocks[$key] = '<?php' . $matches[1] . '?>';
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
        return preg_replace('/\@extends\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?php $view->setExtends(\'$1\'); ?>', $template);
    }

    /**
     * Compile @section and @endsection directives
     */
    private function compileSections(string $template): string
    {
        // @section('name')
        $template = preg_replace('/\@section\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?php $view->startSection(\'$1\'); ?>', $template);

        // @section('name', 'content') - inline section
        $template = preg_replace('/\@section\s*\(\s*[\'"]([^\'"]+)[\'"]?\s*,\s*[\'"]([^\'"]*)[\'"]?\s*\)/', '<?php $view->startSection(\'$1\'); echo \'$2\'; $view->endSection(); ?>', $template);

        // @endsection
        $template = preg_replace('/\@endsection/', '<?php $view->endSection(); ?>', $template);

        // @stop (alias for @endsection)
        $template = preg_replace('/\@stop/', '<?php $view->endSection(); ?>', $template);

        // @show (end section and immediately yield it)
        $template = preg_replace('/\@show/', '<?php $view->endSection(); echo $view->yieldSection($view->getCurrentSection()); ?>', $template);

        return $template;
    }

    /**
     * Compile @yield directives
     */
    private function compileYields(string $template): string
    {
        // @yield('section', 'default')
        $template = preg_replace('/\@yield\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*[\'"]([^\'"]*)[\'"])?\s*\)/', '<?php echo $view->yieldSection(\'$1\', \'$2\'); ?>', $template);

        // @yield('section', $variable)
        $template = preg_replace('/\@yield\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*([^,\)\'\"]+))?\s*\)/', '<?php echo $view->yieldSection(\'$1\', $2 ?? \'\'); ?>', $template);

        return $template;
    }

    /**
     * Compile @include directive
     * 
     * @param string $template
     * @return string
     */
    private function compileIncludes(string $template): string
    {
        // @include('template', ['data' => 'value'])
        $template = preg_replace(
            '/\@include\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*(\[.*?\]))?\s*\)/',
            '<?php echo $view->include(\'$1\', $2 ?? []); ?>',
            $template
        );

        return $template;
    }

    /**
     * Compile components <x-component>
     * 
     * @param string $template
     * @return string
     */
    private function compileComponents(string $template): string
    {
        // Self-closing components: <x-alert message="hello" />
        $template = preg_replace_callback('/<x-([a-zA-Z0-9\-_]+)([^>]*?)\/?>/', function ($matches) {
            $component = $matches[1];
            $attributes = $this->parseComponentAttributes($matches[2]);
            return "<?php echo \$view->component('$component', $attributes); ?>";
        }, $template);

        // Opening and closing components: <x-card>content</x-card>
        $template = preg_replace_callback('/<x-([a-zA-Z0-9\-_]+)([^>]*?)>(.*?)<\/x-\1>/s', function ($matches) {
            $component = $matches[1];
            $attributes = $this->parseComponentAttributes($matches[2]);
            $slot = trim($matches[3]);

            // Add slot to attributes
            $attributes = rtrim($attributes, ']') . ", 'slot' => '" . addslashes($slot) . "']";

            return "<?php echo \$view->component('$component', $attributes); ?>";
        }, $template);

        return $template;
    }

    /**
     * Parse component attributes
     * 
     * @param string $attributeString
     * @return string
     */
    private function parseComponentAttributes(string $attributeString): string
    {
        $attributes = [];

        // Match attribute="value" or :attribute="$variable"
        preg_match_all('/\s*([a-zA-Z0-9\-_]+)\s*=\s*[\'"]([^\'"]*)[\'"]/', $attributeString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2];

            // Check if it's a variable binding :attribute
            if (strpos($attributeString, ":$key") !== false) {
                $attributes[] = "'$key' => $value";
            } else {
                $attributes[] = "'$key' => '" . addslashes($value) . "'";
            }
        }

        return '[' . implode(', ', $attributes) . ']';
    }

    /**
     * Compile echo statements {{ }} and {!! !!}
     * 
     * @param string $template
     * @return string
     */
    private function compileEchos(string $template): string
    {
        // Raw echo {!! !!}
        $template = preg_replace('/\{\!\!\s*(.+?)\s*\!\!\}/', '<?php echo $1; ?>', $template);

        // Escaped echo {{ }}
        $template = preg_replace('/\{\{\s*(.+?)\s*\}\}/', '<?php echo htmlspecialchars($1, ENT_QUOTES, \'UTF-8\'); ?>', $template);

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
        // @if, @elseif, @else, @endif
        $template = preg_replace('/\@if\s*\(\s*(.+?)\s*\)/', '<?php if($1): ?>', $template);
        $template = preg_replace('/\@elseif\s*\(\s*(.+?)\s*\)/', '<?php elseif($1): ?>', $template);
        $template = preg_replace('/\@else/', '<?php else: ?>', $template);
        $template = preg_replace('/\@endif/', '<?php endif; ?>', $template);

        // @unless, @endunless
        $template = preg_replace('/\@unless\s*\(\s*(.+?)\s*\)/', '<?php if(!($1)): ?>', $template);
        $template = preg_replace('/\@endunless/', '<?php endif; ?>', $template);

        // @isset, @endisset
        $template = preg_replace('/\@isset\s*\(\s*(.+?)\s*\)/', '<?php if(isset($1)): ?>', $template);
        $template = preg_replace('/\@endisset/', '<?php endif; ?>', $template);

        // @empty, @endempty
        $template = preg_replace('/\@empty\s*\(\s*(.+?)\s*\)/', '<?php if(empty($1)): ?>', $template);
        $template = preg_replace('/\@endempty/', '<?php endif; ?>', $template);

        // @foreach, @endforeach
        $template = preg_replace('/\@foreach\s*\(\s*(.+?)\s*\)/', '<?php foreach($1): ?>', $template);
        $template = preg_replace('/\@endforeach/', '<?php endforeach; ?>', $template);

        // @for, @endfor
        $template = preg_replace('/\@for\s*\(\s*(.+?)\s*\)/', '<?php for($1): ?>', $template);
        $template = preg_replace('/\@endfor/', '<?php endfor; ?>', $template);

        // @while, @endwhile
        $template = preg_replace('/\@while\s*\(\s*(.+?)\s*\)/', '<?php while($1): ?>', $template);
        $template = preg_replace('/\@endwhile/', '<?php endwhile; ?>', $template);

        // @share
        $template = preg_replace('/\@share\s*\(\s*(.+?)\s*\)/', '<?php $view->share($1); ?>', $template);

        // @errors
        $template = preg_replace('/\@errors\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?php if($errors->has(\'$1\')): ?>', $template);
        $template = preg_replace('/\@enderrors/', '<?php endif; ?>', $template);

        // @error (single error)
        $template = preg_replace('/\@error\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?php if($errors->has(\'$1\')): ?>', $template);
        $template = preg_replace('/\@enderror/', '<?php endif; ?>', $template);

        // @csrf
        $template = preg_replace('/\@csrf/', '<?php echo csrf(); ?>', $template);

        // @method
        $template = preg_replace('/\@method\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?php echo method(\'$1\'); ?>', $template);

        // Compile custom directives
        foreach ($this->customDirectives as $directive => $callback) {
            $template = preg_replace_callback('/\@' . $directive . '\s*\(\s*(.+?)\s*\)/', fn($matches) => $callback($matches[1]), $template);
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
        return preg_replace('/\@php\s*\(\s*(.+?)\s*\)/', '<?php $1; ?>', $template);
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
     * Register a component alias
     * 
     * @param string $alias
     * @param string $component
     * @return void
     */
    public function component(string $alias, string $component): void
    {
        $this->componentAliases[$alias] = $component;
    }

    /**
     * Get compiled template path
     * 
     * @param string $template
     * @return string
     */
    public function getCompiledPath(string $template): string
    {
        return $this->cachePath . '/' . str_replace(['/', '.'], '_', $template) . '_' . md5($template) . '.php';
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

        return filemtime($templatePath) > filemtime($compiledPath);
    }

    /**
     * Ensure cache directory exists
     * 
     * @return void
     */
    private function ensureCacheDirectoryExists(): void
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * Clear compiled templates cache
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $files = glob($this->cachePath . '/*.php');
        foreach ($files as $file) {
            unlink($file);
        }
    }
}