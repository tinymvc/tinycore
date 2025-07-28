<?php

namespace Spark\View;

use Spark\Foundation\Application;
use Spark\Http\Request;
use Spark\Http\Session;
use Spark\Support\Traits\Macroable;
use Spark\View\BladeCompiler;
use Spark\View\Contracts\BladeCompilerContract;
use Spark\View\Contracts\ViewContract;
use Spark\View\Exceptions\UndefinedViewDirectoryPathException;
use Spark\View\Exceptions\ViewException;

/**
 * Class View
 * 
 * Handles template rendering with Blade-like template engine
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class View implements ViewContract
{
    use Macroable;

    /**
     * Path to the directory containing template files
     * 
     * @var string
     */
    private string $path;

    /**
     * Array of paths to use for template resolution
     * 
     * @var array
     */
    private array $usePath = [];

    /**
     * Path to store compiled templates
     * 
     * @var string
     */
    private string $cachePath;

    /**
     * The Blade compiler instance
     * 
     * @var \Spark\View\Contracts\BladeCompilerContract
     */
    private BladeCompilerContract $compiler;

    /**
     * Shared data across all views
     * 
     * @var array
     */
    private static array $shared = [];

    /**
     * View composers callbacks
     * 
     * @var array
     */
    private static array $composers = [];

    /**
     * Stack of sections
     * 
     * @var array
     */
    private array $sections = [];

    /**
     * Current section being captured
     * 
     * @var string|null
     */
    private ?string $currentSection = null;

    /**
     * Template that this view extends
     * 
     * @var string|null
     */
    private ?string $extendsTemplate = null;

    /**
     * Initializes the template path and compiler.
     *
     * @param string|null $path Optional base path for templates
     * @param string|null $cachePath Optional cache path for compiled templates
     */
    public function __construct(?string $path = null, ?string $cachePath = null)
    {
        $path ??= config('views_dir', resource_dir('views'));
        $cachePath ??= config('view_cache_dir', storage_dir('temp/views'));

        if ($path === null) {
            throw new UndefinedViewDirectoryPathException('Views directory path is not set.');
        }

        $this->setPath($path);
        $this->setCachePath($cachePath);

        $this->compiler = new BladeCompiler($this->cachePath);

        // Merge shared data with the application context
        self::$shared = array_merge([
            'app' => Application::$app,
            'request' => Application::$app->get(Request::class),
            'session' => Application::$app->get(Session::class),
            'errors' => Application::$app->get(Request::class)->getErrorObject(),
        ], self::$shared);
    }

    /**
     * Sets the base path for templates.
     *
     * @param string $path The directory path for the template files.
     * @return self
     */
    public function setPath(string $path): self
    {
        $this->path = dir_path($path);
        return $this;
    }

    /**
     * Get the base path for templates.
     *
     * @return string The directory path for the template files.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Sets the cache path for compiled templates.
     *
     * @param string $cachePath The directory path for compiled templates.
     * @return self
     */
    public function setCachePath(string $cachePath): self
    {
        $this->cachePath = dir_path($cachePath);
        return $this;
    }

    /**
     * Get the cache path for compiled templates.
     *
     * @return string The directory path for compiled templates.
     */
    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * Set the paths to use for template resolution
     * 
     * @param string|array $paths
     * @return self
     */
    public function setUsePath(string|array $paths): self
    {
        $this->usePath = array_map('dir_path', (array) $paths);
        return $this;
    }

    /**
     * Get the Blade compiler instance
     * 
     * @return BladeCompiler
     */
    public function getCompiler(): BladeCompiler
    {
        return $this->compiler;
    }

    /**
     * Share data with all views
     * 
     * @param string|array $key
     * @param mixed $value
     * @return void
     */
    public static function share($key, $value = null): void
    {
        if (is_array($key)) {
            self::$shared = array_merge(self::$shared, $key);
        } else {
            self::$shared[$key] = $value;
        }
    }

    /**
     * Register a view composer
     * 
     * @param string|array $views
     * @param callable $composer
     * @return void
     */
    public static function composer($views, callable $composer): void
    {
        $views = is_array($views) ? $views : [$views];

        foreach ($views as $view) {
            self::$composers[$view][] = $composer;
        }
    }

    /**
     * Set the template that this view extends
     * 
     * @param string $template
     * @return void
     */
    public function setExtends(string $template): void
    {
        $this->extendsTemplate = $template;
    }

    /**
     * Renders the specified template with the provided context.
     *
     * @param string $template The template file to render.
     * @param array $context Optional additional context.
     * @return string The rendered content.
     */
    public function render(string $template, array $context = []): string
    {
        // Run view composers
        $this->runComposers($template);

        $templatePath = $this->getTemplatePath($template);
        $compiledPath = $this->compiler->getCompiledPath($template);

        // Compile template if needed
        if ($this->compiler->isExpired($templatePath, $compiledPath)) {
            $this->compiler->compile($templatePath, $compiledPath);
        }

        // Merge shared data with the context
        $context = array_merge(self::$shared, $context);

        // Reset state for this render
        $this->extendsTemplate = null;
        $this->sections = [];
        $this->currentSection = null;

        // Render compiled template
        $content = $this->renderCompiledTemplate($compiledPath, $context);

        // Handle extends - if this template extends another template
        if ($this->extendsTemplate) {
            // Merge current sections with parent context
            $parentContext = array_merge($context, ['sections' => $this->sections]);
            return $this->render($this->extendsTemplate, $parentContext);
        }

        return $content;
    }

    /**
     * Include a template
     * 
     * @param string $template
     * @param array $context
     * @return string
     */
    public function include(string $template, array $context = []): string
    {
        $templatePath = $this->getTemplatePath($template);
        $compiledPath = $this->compiler->getCompiledPath($template);

        // Compile template if needed
        if ($this->compiler->isExpired($templatePath, $compiledPath)) {
            $this->compiler->compile($templatePath, $compiledPath);
        }

        // Merge shared data with the context and make sections available
        $context = array_merge(self::$shared, $context);
        $context['sections'] = $this->sections;

        return $this->renderCompiledTemplate($compiledPath, $context);
    }

    /**
     * Render a component
     * 
     * @param string $component
     * @param array $context
     * @return string
     */
    public function component(string $component, array $context = []): string
    {
        $componentPath = $this->getTemplatePath("components/$component");
        $compiledPath = $this->compiler->getCompiledPath("components/$component");

        // Compile component if needed
        if ($this->compiler->isExpired($componentPath, $compiledPath)) {
            $this->compiler->compile($componentPath, $compiledPath);
        }

        // Merge shared data with the context
        $context = array_merge(self::$shared, $context);

        return $this->renderCompiledTemplate($compiledPath, $context);
    }

    /**
     * Get current section name (for @show directive)
     * 
     * @return string|null
     */
    public function getCurrentSection(): ?string
    {
        return $this->currentSection;
    }

    /**
     * Start a section
     * 
     * @param string $name
     * @return void
     */
    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    /**
     * End a section
     * 
     * @return void
     */
    public function endSection(): void
    {
        if ($this->currentSection) {
            $this->sections[$this->currentSection] = ob_get_clean();
            $this->currentSection = null;
        }
    }

    /**
     * Yield a section
     * 
     * @param string $name
     * @param string $default
     * @return string
     */
    public function yieldSection(string $name, string $default = ''): string
    {
        // Check if sections are passed from child template first
        if (isset($GLOBALS['sections']) && isset($GLOBALS['sections'][$name])) {
            return $GLOBALS['sections'][$name];
        }

        // Check local sections
        return $this->sections[$name] ?? $default;
    }

    /**
     * Check if a section exists
     *
     * @param string $name
     * @return bool
     */
    public function hasSection(string $name): bool
    {
        // Check if sections are passed from child template
        if (isset($GLOBALS['sections']) && isset($GLOBALS['sections'][$name])) {
            return true;
        }

        // Check local sections
        return isset($this->sections[$name]);
    }

    /**
     * Get the full template path
     * 
     * @param string $template
     * @return string
     */
    private function getTemplatePath(string $template): string
    {
        $template = str_replace('.', '/', $template);

        // Check if the template is exists then return it
        $bladePath = dir_path("{$this->path}/$template.blade.php");
        if (is_file($bladePath)) {
            return $bladePath;
        }

        // Check in additional paths if set
        foreach ($this->usePath as $path) {
            $bladePath = dir_path("$path/$template.blade.php");
            if (is_file($bladePath)) {
                return $bladePath;
            }
        }

        throw new ViewException("Template [$template] not found in path [{$this->path}]");
    }

    /**
     * Render a compiled template
     * 
     * @param string $compiledPath
     * @param array $context
     * @return string
     */
    private function renderCompiledTemplate(string $compiledPath, array $context): string
    {
        extract($context);

        // Store current global sections state
        $previousSections = $GLOBALS['sections'] ?? null;

        // Make sections available globally for @yield to access
        if (isset($context['sections'])) {
            $GLOBALS['sections'] = array_merge($this->sections, $context['sections']);
        } else {
            $GLOBALS['sections'] = $this->sections;
        }

        ob_start();
        include dir_path($compiledPath);
        $content = ob_get_clean();

        // Restore previous sections state
        if ($previousSections !== null) {
            $GLOBALS['sections'] = $previousSections;
        } else {
            unset($GLOBALS['sections']);
        }

        return $content;
    }

    /**
     * Run view composers for a template
     * 
     * @param string $template
     * @return void
     */
    private function runComposers(string $template): void
    {
        if (isset(self::$composers[$template])) {
            foreach (self::$composers[$template] as $composer) {
                $composer($this);
            }
        }

        // Run wildcard composers
        if (isset(self::$composers['*'])) {
            foreach (self::$composers['*'] as $composer) {
                $composer($this);
            }
        }
    }

    /**
     * Checks if the specified template exists
     *
     * @param string $template The template file name without extension.
     * @return bool True if the template file exists, otherwise false.
     */
    public function templateExists(string $template): bool
    {
        try {
            $this->getTemplatePath($template);
            return true;
        } catch (ViewException $e) {
            return false;
        }
    }

    /**
     * Clear the view cache
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $this->compiler->clearCache();
    }

    /**
     * Add a custom directive to the compiler
     * 
     * @param string $name
     * @param callable $callback
     * @return void
     */
    public function directive(string $name, callable $callback): void
    {
        $this->compiler->directive($name, $callback);
    }

    /**
     * Helper method for compiling class arrays
     * This should be available in your view context
     * 
     * @param array $classes
     * @return string
     */
    public function compileClassArray(array $classes): string
    {
        $compiledClasses = [];

        foreach ($classes as $key => $value) {
            if (is_numeric($key)) {
                // Simple class name like 'p-4'
                if ($value) {
                    $compiledClasses[] = $value;
                }
            } else {
                // Conditional class like 'font-bold' => $isActive
                if ($value) {
                    $compiledClasses[] = $key;
                }
            }
        }

        return implode(' ', $compiledClasses);
    }

    /**
     * Helper method for compiling attributes arrays
     * This should be available in your view context
     * 
     * @param array $attributes
     * @return string
     */
    public function compileAttributesArray(array $attributes): string
    {
        $compiledAttributes = [];

        foreach ($attributes as $key => $value) {
            if (is_numeric($key)) {
                // Simple attribute like 'data-id="123"'
                if ($value) {
                    $compiledAttributes[] = $value;
                }
            } else {
                // Conditional attribute like 'disabled' => $isDisabled
                if ($value) {
                    $compiledAttributes[] = "$key=\"$value\"";
                }
            }
        }

        return implode(' ', $compiledAttributes);
    }

    /**
     * Helper method for compiling style arrays
     * This should be available in your view context
     * 
     * @param array $styles
     * @return string
     */
    public function compileStyleArray(array $styles): string
    {
        $compiledStyles = [];

        foreach ($styles as $key => $value) {
            if (is_numeric($key)) {
                // Simple style like 'background-color: red'
                if ($value) {
                    $compiledStyles[] = $value;
                }
            } else {
                // Conditional style like 'font-weight: bold' => $isActive
                if ($value) {
                    $compiledStyles[] = $key;
                }
            }
        }

        return implode('; ', $compiledStyles);
    }
}
