<?php

namespace Spark\View;

use Spark\Foundation\Application;
use Spark\Http\Request;
use Spark\Http\Session;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use Spark\View\BladeCompiler;
use Spark\View\Contracts\BladeCompilerContract;
use Spark\View\Contracts\BladeContract;
use Spark\View\Exceptions\UndefinedViewDirectoryPathException;
use Spark\View\Exceptions\ViewException;
use function array_key_exists;
use function count;
use function is_array;

/**
 * Class Blade
 * 
 * Handles template rendering with Blade-like template engine
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Blade implements BladeContract
{
    use Macroable, Conditionable;

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
     * @var BladeCompilerContract
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
    private string|null $currentSection = null;

    /**
     * Stack of nested section names
     *
     * @var array
     */
    private array $sectionStack = [];

    /**
     * Template that this view extends
     * 
     * @var string|null
     */
    private string|null $extendsTemplate = null;

    /**
     * Stacks for @push, @prepend, @stack
     * 
     * @var array<string, array<int, string>>
     */
    private array $stacks = [];

    /**
     * Active push operations: [[name, prepend]]
     * 
     * @var array<int, array{0: string, 1: bool}>
     */
    private array $pushStack = [];

    /**
     * Set of rendered once IDs
     * 
     * @var array<string, bool>
     */
    private array $renderedOnce = [];

    /**
     * Current render depth (for nested includes)
     * 
     * @var int
     */
    private int $renderDepth = 0;

    /**
     * Initializes the template path and compiler.
     *
     * @param string|null $path Optional base path for templates
     * @param string|null $cachePath Optional cache path for compiled templates
     */
    public function __construct(string|null $path = null, string|null $cachePath = null)
    {
        $path ??= views_dir();
        $cachePath ??= storage_dir('framework/views');

        if (empty($path)) {
            throw new UndefinedViewDirectoryPathException('Views directory path is not set.');
        }

        $this->setPath($path);
        $this->setCachePath($cachePath);

        $this->compiler = new BladeCompiler($this->cachePath);

        // Merge shared data with the application context
        self::$shared = [...self::$shared, 'app' => Application::$app];

        // Add request, session, and errors to shared data if not in CLI
        if ((is_web() || Application::$app->isTesting()) && Application::$app->resolved(Request::class)) {
            self::$shared = [
                ...self::$shared,
                'request' => Application::$app->get(Request::class),
                'session' => Application::$app->get(Session::class),
                'errors' => Application::$app->get(Request::class)->errors(),
            ];
        }
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
     * @param string $path The directory path to use
     * @param string $id The identifier for the path
     * @return self
     */
    public function setUsePath(string $path, string $id = ''): self
    {
        $path = dir_path($path);

        if (!empty($id)) {
            $this->usePath[$id] = $path;
        } else {
            $this->usePath[] = $path;
        }

        return $this;
    }

    /**
     * Alias for setUsePath to maintain facade method signature
     * 
     * @param string $path The directory path to use
     * @param string|null $id The identifier for the path
     * @return self
     */
    public function usePath(string $path, string $id = ''): self
    {
        return $this->setUsePath($path, $id);
    }

    /**
     * Get the paths used for template resolution
     * 
     * @return array
     */
    public function getUsePath(): array
    {
        return $this->usePath;
    }

    /**
     * Get the Blade compiler instance
     * 
     * @return BladeCompilerContract
     */
    public function getCompiler(): BladeCompilerContract
    {
        return $this->compiler;
    }

    /**
     * Clear shared view data and composers between application tests
     * 
     * @return void
     */
    public static function flushState(): void
    {
        self::$shared = [];
        self::$composers = [];
    }

    /**
     * Share data with all views
     * 
     * @param string|array $key
     * @param mixed $value
     * @return void
     */
    public static function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            self::$shared = [...self::$shared, ...$key];
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
    public static function composer(string|array $views, callable $composer): void
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
    public function setExtends(mixed $template): void
    {
        $this->extendsTemplate = (string) $template;
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
        $context = [...self::$shared, ...$context];

        $nested = $this->renderDepth > 0;
        $previousState = [
            $this->extendsTemplate,
            $this->sections,
            $this->currentSection,
            $this->sectionStack,
            $this->pushStack,
        ];
        $this->extendsTemplate = null;
        $this->sections = $context['sections'] ?? [];
        $this->currentSection = null;
        $this->sectionStack = [];
        $this->pushStack = [];

        try {
            $content = $this->renderCompiledTemplate($compiledPath, $context);

            if ($this->extendsTemplate) {
                $parentContext = [
                    ...$context,
                    'sections' => $this->sections,
                    'stacks' => $this->stacks,
                    'renderedOnce' => $this->renderedOnce,
                ];
                return $this->render($this->extendsTemplate, $parentContext);
            }

            return $content;
        } finally {
            if ($nested) {
                [
                    $this->extendsTemplate,
                    $this->sections,
                    $this->currentSection,
                    $this->sectionStack,
                    $this->pushStack,
                ] = $previousState;
            }
        }
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
        $this->runComposers($template);

        $templatePath = $this->getTemplatePath($template);
        $compiledPath = $this->compiler->getCompiledPath($template);

        // Compile template if needed
        if ($this->compiler->isExpired($templatePath, $compiledPath)) {
            $this->compiler->compile($templatePath, $compiledPath);
        }

        // Merge shared data with the context and make sections available
        $context = [...self::$shared, ...$context];
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
        if (str_contains($component, '::')) {
            $component = str_replace('::', '::components/', $component);
        } else {
            $component = "components/$component";
        }

        $this->runComposers($component);

        $componentPath = $this->getTemplatePath($component);
        $compiledPath = $this->compiler->getCompiledPath($component);

        // Compile component if needed
        if ($this->compiler->isExpired($componentPath, $compiledPath)) {
            $this->compiler->compile($componentPath, $compiledPath);
        }

        // Extract slot content if provided
        $slot = $context['slot'] ?? '';
        unset($context['slot']);

        // Separate named slots (instances of ComponentSlot) from regular attributes
        $namedSlots = [];
        foreach ($context as $key => $value) {
            if ($value instanceof ComponentSlot) {
                $namedSlots[$key] = $value;
                unset($context[$key]);
            }
        }

        // Normalize direct component calls to the same slot type as compiled tags.
        if (!$slot instanceof ComponentSlot) {
            $slot = new ComponentSlot((string) $slot);
        }

        // Merge shared data with the context
        $context = [
            ...self::$shared,
            ...$namedSlots,
            'slot' => $slot,
            'attributes' => new Attributes($context),
        ];

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
    public function startSection(mixed $name): void
    {
        $sectionName = (string) $name;
        $this->sectionStack[] = $sectionName;
        $this->currentSection = $sectionName;
        ob_start();
    }

    /**
     * End a section
     * 
     * @return void
     */
    public function endSection(): void
    {
        if (!empty($this->sectionStack)) {
            $section = array_pop($this->sectionStack);
            $this->sections[$section] = ob_get_clean();
            $this->currentSection = $this->sectionStack ? array_values($this->sectionStack)[count($this->sectionStack) - 1] : null;
        }
    }

    /**
     * End section and return its content.
     *
     * @return string
     */
    public function stopAndYieldSection(): string
    {
        $name = $this->getCurrentSection();
        if ($name !== null) {
            $this->endSection();
        }

        return $this->yieldSection($name ?? '', '');
    }

    /**
     * Yield a section
     * 
     * @param string $name
     * @param string $default
     * @return string
     */
    public function yieldSection(string $name, mixed $default = ''): string
    {
        // Check if sections are passed from child template first
        if (isset($GLOBALS['sections']) && array_key_exists($name, $GLOBALS['sections'])) {
            return $GLOBALS['sections'][$name];
        }

        // Check local sections
        if (array_key_exists($name, $this->sections)) {
            return $this->sections[$name];
        }

        return $default;
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
        if (isset($GLOBALS['sections']) && array_key_exists($name, $GLOBALS['sections'])) {
            return true;
        }

        // Check local sections
        return array_key_exists($name, $this->sections);
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

        $hasTemplateId = str_contains($template, '::');

        if ($hasTemplateId) {
            [$id, $templateName] = explode('::', $template, 2);

            // Check if the template ID is registered
            // If not, throw an exception
            if (isset($this->usePath[$id])) {
                $bladePath = dir_path("{$this->usePath[$id]}/$templateName.blade.php");
                if (is_file($bladePath)) {
                    return $bladePath;
                } else {
                    throw new ViewException("Template [$templateName] not found in path [{$this->usePath[$id]}]");
                }
            } else {
                throw new ViewException("No path registered with identifier [$id]");
            }
        }

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
        $previousSections = $GLOBALS['sections'] ?? null;
        if ($this->renderDepth === 0) {
            $this->stacks = $context['stacks'] ?? [];
            $this->renderedOnce = $context['renderedOnce'] ?? [];
        }
        $__bladePreviousState = [
            $this->extendsTemplate,
            $this->sections,
            $this->currentSection,
            $this->sectionStack,
            $this->pushStack,
            $this->stacks,
            $this->renderedOnce,
        ];
        $__bladeCompleted = false;
        $this->renderDepth++;

        // Make sections available globally for @yield to access
        if (isset($context['sections'])) {
            $GLOBALS['sections'] = [...$this->sections, ...$context['sections']];
        } else {
            $GLOBALS['sections'] = $this->sections;
        }

        $started = microtime(true);
        $startedMemory = memory_get_usage(true);

        $bufferLevel = ob_get_level();
        extract($context, EXTR_SKIP); // Context must not replace renderer state or the include path.

        try {
            ob_start();
            include dir_path($compiledPath);
            $content = ob_get_clean();
            $__bladeCompleted = true;
        } finally {
            $this->renderDepth--;
            if (!$__bladeCompleted) {
                [
                    $this->extendsTemplate,
                    $this->sections,
                    $this->currentSection,
                    $this->sectionStack,
                    $this->pushStack,
                    $this->stacks,
                    $this->renderedOnce,
                ] = $__bladePreviousState;
            }
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            if ($previousSections !== null) {
                $GLOBALS['sections'] = $previousSections;
            } else {
                unset($GLOBALS['sections']);
            }
        }

        \Spark\Telemetry::emit(
            'bladeTemplateRendered',
            $compiledPath,
            fn() => round((microtime(true) - $started) * 1000, 6),
            $startedMemory,
            fn() => array_keys($context),
        );

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
     * Determine if a template exists (alias of templateExists).
     *
     * @param string $template
     * @return bool
     */
    public function exists(string $template): bool
    {
        return $this->templateExists($template);
    }

    /**
     * Start pushing content onto a stack.
     */
    public function startPush(mixed $name, bool $prepend = false): void
    {
        $stackName = (string) $name;
        $this->pushStack[] = [$stackName, $prepend];
        ob_start();
    }

    /**
     * Stop pushing content onto a stack.
     */
    public function endPush(): void
    {
        if (!empty($this->pushStack)) {
            [$name, $prepend] = array_pop($this->pushStack);
            $content = ob_get_clean();

            if ($prepend) {
                $this->stacks[$name] ??= [];
                array_unshift($this->stacks[$name], $content);
            } else {
                $this->stacks[$name][] = $content;
            }
        }
    }

    /**
     * Yield the content of a stack.
     */
    public function yieldStack(mixed $name, mixed $default = ''): string
    {
        $stackName = (string) $name;
        $items = $this->stacks[$stackName] ?? null;

        if ($items !== null) {
            return implode('', $items);
        }

        return (string) $default;
    }

    /**
     * Determine if a given once token has already been rendered.
     */
    public function hasRenderedOnce(string $id): bool
    {
        return isset($this->renderedOnce[$id]);
    }

    /**
     * Mark a once token as rendered.
     */
    public function markRenderedOnce(string $id): void
    {
        $this->renderedOnce[$id] = true;
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
     * Helper method for compiling attributes arrays
     * This should be available in your view context
     * 
     * @param array $attributes
     * @return Attributes
     */
    public function compileAttributes(array $attributes): Attributes
    {
        return new Attributes($attributes);
    }
}
