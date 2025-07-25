<?php

namespace Spark\View\Contracts;

/** Interface BladeCompilerContract */
interface BladeCompilerContract
{
    /**
     * Compiles a Blade template string into a PHP string.
     *
     * @param string $template The Blade template string to compile.
     * @return string The compiled PHP string.
     */
    public function compile(string $templatePath, string $compiledPath): void;

    /**
     * Compiles a Blade template string into a PHP string.
     *
     * @param string $template The Blade template string to compile.
     * @return string The compiled PHP string.
     */
    public function compileString(string $template): string;

    /**
     * Gets the path to the compiled Blade template.
     *
     * @param string $template The path to the Blade template file.
     * @return string The path to the compiled PHP file.
     */
    public function getCompiledPath(string $template): string;

    /**
     * Registers a custom Blade directive.
     *
     * @param string $name The name of the directive.
     * @param callable $callback The callback function that defines the directive's behavior.
     * @return void
     */
    public function directive(string $name, callable $callback): void;

    /**
     * Checks if a Blade template is expired.
     *
     * @param string $templatePath The path to the Blade template file.
     * @param string $compiledPath The path to the compiled PHP file.
     * @return bool Returns true if the template is expired, false otherwise.
     */
    public function isExpired(string $templatePath, string $compiledPath): bool;

    /**
     * Clears the compiled Blade templates cache.
     * This method should remove all compiled Blade files from the cache directory.
     *
     * @return void
     */
    public function clearCache(): void;
}