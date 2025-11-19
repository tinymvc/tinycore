<?php

namespace Spark\Foundation\Services;

use Spark\Console\Commands;
use Spark\Console\Prompt;
use Spark\Database\DB;
use Spark\Foundation\Application;
use Spark\Hash;
use Spark\Queue\Queue;
use Spark\Routing\Router;
use Spark\Translator;
use Spark\Utils\Cache;
use Spark\Utils\FileManager;
use Spark\Utils\Http;
use Spark\Utils\Image;
use Spark\Utils\Mail;
use Throwable;
use function array_slice;
use function count;
use function get_class;
use function gettype;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Tinker - Interactive PHP Shell for TinyMVC
 * 
 * Provides a REPL (Read-Eval-Print Loop) for testing and debugging.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 * @license MIT
 * @version 1.0.0
 */
class Tinker
{
    /** @var array Context variables available in the shell */
    private array $context = [];

    /** @var array Command history */
    private array $history = [];

    /** @var string Input buffer */
    private string $buffer = '';

    /** @var int Current line number */
    private int $lineNumber = 1;

    /** @var bool Whether to use readline */
    private bool $useReadline;

    /** @var resource Stdin handle */
    private $stdin;

    /**
     * Creating Tinker instance and 
     * Initializes the interactive shell environment.
     */
    public function __construct()
    {
        // Determine if we can use readline
        $this->useReadline = $this->canUseReadline();

        // Open stdin once
        $this->stdin = fopen('php://stdin', 'r');

        // Windows console UTF-8 fix
        if (PHP_OS_FAMILY === 'Windows') {
            // Enable ANSI colors on Windows 10+
            if (function_exists('sapi_windows_vt100_support')) {
                sapi_windows_vt100_support(STDOUT, true);
            }

            // Set stream encoding to UTF-8
            if (function_exists('stream_set_blocking')) {
                stream_set_blocking($this->stdin, true);
            }
        }
    }

    /**
     * Check if readline is available and working
     */
    private function canUseReadline(): bool
    {
        // Readline can be problematic on Windows
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return function_exists('readline');
    }

    /**
     * Start the interactive shell
     */
    public function run(): void
    {
        // Set unlimited execution time
        set_time_limit(0);

        // Print welcome banner and auto-import classes
        $this->printBanner();
        $this->autoImportClasses();

        while (true) {
            try {
                $input = $this->readInput();

                // Handle null or empty input
                if ($input === null || $input === '') {
                    continue;
                }

                // Handle exit
                if ($this->isExitCommand($input)) {
                    $this->exit();
                    break;
                }

                // Handle special commands
                if ($this->handleCommand($input)) {
                    continue;
                }

                // Execute PHP code
                $result = $this->evaluate($input);

                // Display result
                $this->display($result);

                $this->lineNumber++;

            } catch (Throwable $e) {
                $this->displayError($e);
                $this->buffer = ''; // Clear buffer on error
            }
        }

        // Close stdin on exit
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }
    }

    /**
     * Auto-import commonly used classes into context
     */
    private function autoImportClasses(): void
    {
        // Import framework services
        $this->context['app'] = Application::$app;
        $this->context['db'] = Application::$app->make(DB::class);
        $this->context['container'] = Application::$app->getContainer();
        $this->context['translator'] = Application::$app->make(Translator::class);
        $this->context['hash'] = Application::$app->make(Hash::class);
        $this->context['http'] = Application::$app->make(Http::class);
        $this->context['queue'] = Application::$app->make(Queue::class);
        $this->context['cache'] = Application::$app->make(Cache::class);
        $this->context['fm'] = Application::$app->make(FileManager::class);
        $this->context['image'] = Application::$app->make(Image::class);

        // Import Mailer if available
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $this->context['mail'] = Application::$app->make(Mail::class);
        }

        // Auto-import all models
        $this->importModels();
    }

    /**
     * Import all models from App\Models
     */
    private function importModels(): void
    {
        $modelsPath = root_dir('app/Models');

        if (!is_dir($modelsPath)) {
            return;
        }

        $files = glob("$modelsPath/*.php");

        if (empty($files)) {
            return;
        }

        foreach ($files as $file) {
            $name = basename($file, '.php');

            // Skip base Model class and any other non-model files
            if (in_array($name, ['Model', 'Cast', 'Casts'])) {
                continue;
            }

            $fullClass = "App\\Models\\$name";

            // Check if class exists and create alias
            if (class_exists($fullClass)) {
                if (!class_exists($name, false)) {
                    class_alias($fullClass, $name);
                }
            }
        }
    }

    /**
     * Read input from user (handles multi-line)
     */
    private function readInput(): ?string
    {
        while (true) {
            $prompt = $this->getPrompt();
            $line = $this->readLine($prompt);

            // Handle EOF or false
            if ($line === false) {
                return null;
            }

            // Important: Only trim newlines/carriage returns, NOT spaces or tabs
            $line = rtrim($line, "\r\n");

            // If buffer is not empty, this is a continuation
            if (!empty($this->buffer)) {
                $this->buffer .= "\n" . $line;
            } else {
                $this->buffer = $line;
            }

            // Check if statement is complete
            if ($this->isComplete($this->buffer)) {
                $code = $this->buffer;
                $this->buffer = '';

                // Add to history (only non-empty)
                if (!empty(trim($code))) {
                    if ($this->useReadline) {
                        readline_add_history($code);
                    }
                    $this->history[] = $code;
                }

                return trim($code);
            }

            // If buffer is empty after trimming, don't continue
            if (empty(trim($this->buffer))) {
                $this->buffer = '';
                return null;
            }
        }
    }

    /**
     * Read a single line of input
     */
    private function readLine(string $prompt): string|false
    {
        if ($this->useReadline) {
            return readline($prompt);
        }

        // Manual reading for Windows/non-readline systems
        echo $prompt;
        $line = fgets($this->stdin);

        return $line;
    }

    /**
     * Check if code statement is complete
     */
    private function isComplete(string $code): bool
    {
        $trimmed = trim($code);

        // Empty code is not complete
        if (empty($trimmed)) {
            return false;
        }

        // This prevents URLs like https:// from being treated as comments
        $codeNoStrings = $this->removeStrings($trimmed);

        // Now remove comments from the code (strings are already gone)
        $codeNoStrings = preg_replace('/\/\/.*$/m', '', $codeNoStrings);
        $codeNoStrings = preg_replace('/\/\*.*?\*\//s', '', $codeNoStrings);

        // Count braces, brackets, parentheses
        $openBraces = substr_count($codeNoStrings, '{') - substr_count($codeNoStrings, '}');
        $openBrackets = substr_count($codeNoStrings, '[') - substr_count($codeNoStrings, ']');
        $openParens = substr_count($codeNoStrings, '(') - substr_count($codeNoStrings, ')');

        // If any are open, not complete
        if ($openBraces > 0 || $openBrackets > 0 || $openParens > 0) {
            return false;
        }

        // If any are mismatched (negative), treat as incomplete to avoid errors
        if ($openBraces < 0 || $openBrackets < 0 || $openParens < 0) {
            return false;
        }

        // Check for semicolon or closing brace at the end
        $lastChar = substr(rtrim($trimmed), -1);

        // Complete if ends with semicolon, closing brace, or closing bracket
        if (in_array($lastChar, [';', '}', ']'])) {
            return true;
        }

        // For single-line expressions without semicolon, check if it looks complete
        // This allows things like: User::all()
        if (!str_contains($trimmed, "\n")) {
            // If it's a simple expression (method call, variable, etc), consider it complete
            // But only if braces are balanced
            if ($openBraces === 0 && $openBrackets === 0 && $openParens === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove all string literals from code
     * 
     * This parser walks through code character-by-character and removes
     * the content of all string literals (single and double quoted),
     * leaving only empty quotes in their place.
     */
    private function removeStrings(string $code): string
    {
        $result = '';
        $length = strlen($code);
        $i = 0;

        while ($i < $length) {
            $char = $code[$i];

            // Check if we found a quote (string start)
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $result .= $quote; // Add opening quote
                $i++;

                // Parse until we find the matching unescaped closing quote
                $escaped = false;
                while ($i < $length) {
                    $char = $code[$i];

                    if ($escaped) {
                        // Previous char was backslash, current char is escaped
                        // Skip this character (don't add to result)
                        $escaped = false;
                        $i++;
                    } elseif ($char === '\\') {
                        // Found backslash - next char will be escaped
                        $escaped = true;
                        $i++;
                    } elseif ($char === $quote) {
                        // Found unescaped closing quote - string ends here
                        $result .= $quote; // Add closing quote
                        $i++;
                        break;
                    } else {
                        // Regular character inside string - skip it
                        $i++;
                    }
                }
            } else {
                // Regular character outside strings - keep it
                $result .= $char;
                $i++;
            }
        }

        return $result;
    }

    /**
     * Evaluate PHP code
     * 
     * This method executes user code and maintains variable persistence across commands.
     * Variables defined in one command are available in subsequent commands.
     * Models are auto-imported via class_alias() so you can use User instead of App\Models\User.
     */
    private function evaluate(string $code): mixed
    {
        // Normalize code - remove trailing whitespace and semicolons
        $code = rtrim($code);
        $code = rtrim($code, ';');

        // Check if it's an expression
        $isExpr = $this->isExpression($code);

        // Wrap in return if it's an expression
        if ($isExpr) {
            $code = "return ($code);";
        } else {
            $code .= ';';
        }

        // Extract context variables to make them available
        extract($this->context, EXTR_SKIP);

        // Execute with output buffering
        ob_start();

        try {
            // Execute the user's code
            $__result__ = eval ($code);

            $capturedOutput = ob_get_contents();
            ob_end_clean();

            // Echo any output
            if (!empty($capturedOutput)) {
                echo $capturedOutput;
            }

            // Capture all variables after execution
            $__allVars__ = get_defined_vars();

            // Update context with new/modified variables
            foreach ($__allVars__ as $name => $value) {
                // Skip internal variables and reserved names
                if (
                    !in_array($name, ['code', '__result__', '__allVars__', 'capturedOutput', 'isExpr'])
                    && !str_starts_with($name, '__')
                ) {
                    $this->context[$name] = $value;
                }
            }

            return $__result__;

        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * Check if code is an expression (should return value)
     */
    private function isExpression(string $code): bool
    {
        $code = trim($code, "; \t\n\r\0\x0B");

        // Empty code
        if (empty($code)) {
            return false;
        }

        // Control structures are not expressions
        $keywords = ['if', 'for', 'foreach', 'while', 'switch', 'function', 'class', 'trait', 'interface'];
        foreach ($keywords as $keyword) {
            if (preg_match('/^' . $keyword . '\s*[\(\{]/i', $code)) {
                return false;
            }
        }

        // Echo, print, etc are not expressions
        if (preg_match('/^(echo|print|var_dump|print_r|var_export)\s+/i', $code)) {
            return false;
        }

        // Variable assignments should not return (unless it's part of expression)
        if (preg_match('/^\$\w+\s*=/', $code) && !preg_match('/^\$\w+\s*=.*;\s*\$\w+/', $code)) {
            return false;
        }

        // Return statements are not expressions
        if (preg_match('/^return\s+/i', $code)) {
            return false;
        }

        return true;
    }

    /**
     * Handle special commands
     */
    private function handleCommand(string $input): bool
    {
        /** @var \Spark\Console\Commands $spark */
        $spark = Application::$app->make(Commands::class);

        $commands = [
            'help' => fn() => $this->showHelp(),
            '?' => fn() => $this->showHelp(),
            'clear' => fn() => $this->clear(),
            'cls' => fn() => $this->clear(),
            'history' => fn() => $this->showHistory(),
            'vars' => fn() => $this->showVars(),
            'models' => fn() => $this->listModels(),
            'routes' => fn() => $this->listRoutes(),
            'config' => fn() => $this->showConfig(),
            'commands' => fn() => $spark->listCommands(),
        ];

        if (isset($commands[$input])) {
            $commands[$input]();
            return true;
        }

        $sparkCmd = $this->parseSparkCommands($input);
        if ($spark->hasCommand($sparkCmd[0])) {
            if ($spark->isDisabled($sparkCmd[0])) {
                $this->color("Command {$sparkCmd[0]} is disabled.", 'yellow');
                return true;
            }

            // Run the Spark command
            Application::$app->resolve(
                $spark->getCommand($sparkCmd[0])['callback'],
                ['args' => Prompt::parseArguments($sparkCmd[1])]
            );
            return true;
        }

        return false;
    }

    /**
     * Parse Spark commands from user input
     */
    public function parseSparkCommands(string $input): array
    {
        $parts = explode(' ', $input);
        $command = array_shift($parts);

        return [$command, $parts]; // Return command and arguments
    }

    /**
     * Display result
     */
    private function display(mixed $value): void
    {
        // Don't display anything for null from statements
        if ($value === null && !$this->isExpression(end($this->history) ?? '')) {
            return;
        }

        echo "\n" . $this->color('=> ', 'green');

        if ($value === null) {
            echo $this->color('null', 'gray');
        } elseif (is_bool($value)) {
            echo $this->color($value ? 'true' : 'false', 'yellow');
        } elseif (is_string($value)) {
            echo $this->color('"' . addslashes($value) . '"', 'cyan');
        } elseif (is_numeric($value)) {
            echo $this->color((string) $value, 'magenta');
        } elseif (is_array($value)) {
            echo $this->formatArray($value);
        } elseif (is_object($value)) {
            echo $this->formatObject($value);
        } else {
            echo var_export($value, true);
        }

        echo "\n\n";
    }

    /**
     * Format array for display
     */
    private function formatArray(array $arr, int $depth = 0, int $maxItems = 50): string
    {
        if (empty($arr)) {
            return '[]';
        }

        $totalCount = count($arr);
        $isList = array_is_list($arr);

        // Adjust max items based on depth
        if ($depth === 0) {
            $maxItems = 50; // Top level: show many items
        } elseif ($depth === 1) {
            $maxItems = 20; // First nesting: still show good amount
        } else {
            $maxItems = 10; // Deeper: reduce
        }

        // For small arrays at depth 0, show inline
        if ($totalCount <= 3 && $depth === 0) {
            $items = [];
            foreach ($arr as $key => $value) {
                $val = $this->formatValue($value, $depth + 1, true);
                $items[] = $isList ? $val : $this->color("\"$key\"", 'cyan') . " => $val";
            }
            return '[' . implode(', ', $items) . ']';
        }

        // Limit items to display
        $displayCount = min($totalCount, $maxItems);
        $items = [];
        $i = 0;

        foreach ($arr as $key => $value) {
            if ($i >= $displayCount) {
                break;
            }

            $formattedValue = $this->formatValue($value, $depth + 1);

            if ($isList) {
                $items[] = $formattedValue;
            } else {
                $keyDisplay = is_string($key) ? $this->color("\"$key\"", 'cyan') : $this->color((string) $key, 'magenta');
                $items[] = "$keyDisplay => $formattedValue";
            }
            $i++;
        }

        // Add truncation notice if needed
        if ($totalCount > $maxItems) {
            $remaining = $totalCount - $maxItems;
            $items[] = $this->color("... and $remaining more items", 'gray');
        }

        $indent = str_repeat('  ', $depth);
        $nextIndent = str_repeat('  ', $depth + 1);

        return "[\n$nextIndent" . implode(",\n$nextIndent", $items) . "\n$indent]";
    }

    /**
     * Format object for display - Universal approach
     */
    private function formatObject(object $obj, int $depth = 0): string
    {
        $class = get_class($obj);
        $shortClass = basename(str_replace('\\', '/', $class));

        if (!$obj instanceof Application) {
            // Try to convert object to array representation
            $data = $this->objectToArray($obj);

            if ($data !== null) {
                return $this->color($shortClass, 'yellow') . ' ' . $this->formatArray($data, $depth);
            }
        }

        // Fallback for objects we can't convert
        return $this->color($shortClass, 'yellow') . ' {...}';
    }

    /**
     * Universal object to array converter
     * Handles: stdClass, Models with toArray, Collections, objects with public properties, etc.
     */
    private function objectToArray(object $obj): ?array
    {
        // Handle stdClass
        if ($obj instanceof \stdClass) {
            return (array) $obj;
        }

        // Handle models/objects with toArray method
        if (method_exists($obj, 'toArray')) {
            try {
                $result = $obj->toArray();
                if (is_array($result)) {
                    return $result;
                }
            } catch (Throwable $e) {
                // Continue to next method
            }
        }

        // Handle collections with all() method
        if (method_exists($obj, 'all')) {
            try {
                $result = $obj->all();
                if (is_array($result)) {
                    return $result;
                }
            } catch (Throwable $e) {
                // Continue to next method
            }
        }

        // Handle objects with getIterator (iterable objects)
        if (method_exists($obj, 'getIterator')) {
            try {
                $result = [];
                foreach ($obj as $key => $value) {
                    $result[$key] = $value;
                }
                if (!empty($result)) {
                    return $result;
                }
            } catch (Throwable $e) {
                // Continue to next method
            }
        }

        // Try to get public properties
        try {
            $reflection = new \ReflectionObject($obj);
            $publicProps = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

            if (!empty($publicProps)) {
                $data = [];
                foreach ($publicProps as $prop) {
                    $data[$prop->getName()] = $prop->getValue($obj);
                }
                return $data;
            }
        } catch (Throwable $e) {
            // Continue to fallback
        }

        // Last resort: try object cast (works for simple objects)
        try {
            $data = (array) $obj;
            if (!empty($data)) {
                return $data;
            }
        } catch (Throwable $e) {
            // Can't convert
        }

        return null;
    }

    /**
     * Format a value for display - Universal approach
     */
    private function formatValue(mixed $value, int $depth = 0, bool $inline = false): string
    {
        // Prevent infinite recursion
        if ($depth > 3) {
            if (is_array($value)) {
                return $this->color('[' . count($value) . ' items]', 'gray');
            } elseif (is_object($value)) {
                $shortClass = basename(str_replace('\\', '/', get_class($value)));
                return $this->color($shortClass, 'yellow') . ' {...}';
            }
        }

        if ($value === null) {
            return $this->color('null', 'gray');
        } elseif (is_bool($value)) {
            return $this->color($value ? 'true' : 'false', 'yellow');
        } elseif (is_string($value)) {
            // Truncate long strings
            if (strlen($value) > 100 && !$inline) {
                $str = substr($value, 0, 97) . '...';
            } elseif (strlen($value) > 50 && $inline) {
                $str = substr($value, 0, 47) . '...';
            } else {
                $str = $value;
            }
            return $this->color('"' . addslashes($str) . '"', 'cyan');
        } elseif (is_numeric($value)) {
            return $this->color((string) $value, 'magenta');
        } elseif (is_array($value)) {
            $count = count($value);

            // At depth 0-1 (top level results), show arrays up to 10 items
            // At depth 2+, only show small arrays (5 items)
            $expandThreshold = ($depth <= 1) ? 10 : 5;

            if ($count <= $expandThreshold) {
                return $this->formatArray($value, $depth);
            }

            // For inline context, show very small arrays
            if ($inline && $count <= 2) {
                return $this->formatArray($value, $depth);
            }

            // Otherwise, show count
            return $this->color("[{$count} items]", 'gray');
        } elseif (is_object($value)) {
            // Universal object handling - try to convert to array
            $data = $this->objectToArray($value);

            if ($data !== null) {
                $itemCount = count($data);
                $shortClass = basename(str_replace('\\', '/', get_class($value)));

                // At depth 0-1 (top level results), show objects with up to 15 properties
                // At depth 2+, only show small objects (5 properties)
                $expandThreshold = ($depth <= 1) ? 15 : 5;

                if ($itemCount <= $expandThreshold) {
                    return $this->color($shortClass, 'yellow') . ' ' . $this->formatArray($data, $depth);
                }

                // For larger objects, show count
                return $this->color($shortClass, 'yellow') . ' ' . $this->color("[{$itemCount} properties]", 'gray');
            }

            // Fallback for objects we can't convert or too deep
            $shortClass = basename(str_replace('\\', '/', get_class($value)));
            return $this->color($shortClass, 'yellow') . ' {...}';
        }

        return var_export($value, true);
    }

    /**
     * Apply color to text
     */
    private function color(string $text, string $color): string
    {
        $colors = [
            'red' => '31',
            'green' => '32',
            'yellow' => '33',
            'blue' => '34',
            'magenta' => '35',
            'cyan' => '36',
            'white' => '37',
            'gray' => '90',
        ];

        $code = $colors[$color] ?? '37';
        return "\033[{$code}m{$text}\033[0m";
    }

    /**
     * Display error
     */
    private function displayError(Throwable $e): void
    {
        $class = basename(str_replace('\\', '/', get_class($e)));
        echo "\n" . $this->color("✖ $class", 'red') . "\n";
        echo $this->color($e->getMessage(), 'yellow') . "\n";

        // Show location for user code errors (but not eval'd code)
        $file = $e->getFile();
        $line = $e->getLine();

        if (!str_contains($file, 'eval()') && !str_contains($file, "eval()'d code")) {
            echo $this->color("at $file:$line", 'gray') . "\n";
        }

        echo "\n";
    }

    /**
     * Print banner
     */
    private function printBanner(): void
    {
        $version = PHP_VERSION;
        $phpVersion = strlen($version) > 10 ? substr($version, 0, 10) : $version;

        echo $this->color("┌─────────────────────────────────────────┐", 'cyan') . "\n";
        echo $this->color("│", 'cyan') . "     TinyMVC Tinker - v1.0.0             " . $this->color("│", 'cyan') . "\n";
        echo $this->color("│", 'cyan') . "     PHP $phpVersion                          " . $this->color("│", 'cyan') . "\n";
        echo $this->color("└─────────────────────────────────────────┘", 'cyan') . "\n";
        echo "\n";
        echo "Type " . $this->color('help', 'yellow') . " for commands, " . $this->color('exit', 'yellow') . " to quit\n\n";
    }

    /**
     * Show help
     */
    private function showHelp(): void
    {
        echo "\n" . $this->color("── Commands ──────────────────────────", 'cyan') . "\n";
        echo "  " . $this->color('help', 'yellow') . "          Show this help\n";
        echo "  " . $this->color('clear', 'yellow') . "         Clear screen\n";
        echo "  " . $this->color('history', 'yellow') . "       Show command history\n";
        echo "  " . $this->color('vars', 'yellow') . "          Show variables\n";
        echo "  " . $this->color('models', 'yellow') . "        List models\n";
        echo "  " . $this->color('routes', 'yellow') . "        Show routes\n";
        echo "  " . $this->color('config', 'yellow') . "        Show configuration\n";
        echo "  " . $this->color('commands', 'yellow') . "      Show Spark commands\n";
        echo "  " . $this->color('exit', 'yellow') . "          Exit tinker\n";
        echo "\n" . $this->color("── Examples ──────────────────────────", 'cyan') . "\n";
        echo "  " . $this->color('// Variables persist across commands', 'gray') . "\n";
        echo "  \$name = \"Shahin\"\n";
        echo "  \$name  " . $this->color('// Access it later', 'gray') . "\n";
        echo "\n";
        echo "  " . $this->color('// Models auto-imported (no namespace needed)', 'gray') . "\n";
        echo "  User::find(1)\n";
        echo "  User::all()\n";
        echo "  User::where('active', 1)->get()\n";
        echo "\n";
        echo "  " . $this->color('// Database queries', 'gray') . "\n";
        echo "  \$db->table('users')->count()\n";
        echo "\n";
    }

    /**
     * Show history
     */
    private function showHistory(): void
    {
        echo "\n" . $this->color("── History ───────────────────────────", 'cyan') . "\n";

        if (empty($this->history)) {
            echo "  No history yet\n";
        } else {
            $recent = array_slice($this->history, -20);
            foreach ($recent as $i => $cmd) {
                $num = count($this->history) - count($recent) + $i + 1;
                echo sprintf("  " . $this->color("%3d", 'gray') . "  %s\n", $num, $cmd);
            }
        }

        echo "\n";
    }

    /**
     * Show variables
     */
    private function showVars(): void
    {
        echo "\n" . $this->color("── Variables ─────────────────────────", 'cyan') . "\n";

        foreach ($this->context as $name => $value) {
            $type = is_object($value) ? get_class($value) : gettype($value);
            echo "  " . $this->color("\$$name", 'yellow') . "  " . $this->color("($type)", 'gray') . "\n";
        }

        echo "\n";
    }

    /**
     * List models
     */
    private function listModels(): void
    {
        echo "\n" . $this->color("── Models (Auto-imported) ────────────", 'cyan') . "\n";

        $modelsPath = root_dir('app/Models');

        if (!is_dir($modelsPath)) {
            echo "  No models directory found\n\n";
            return;
        }

        $files = glob("$modelsPath/*.php");

        if (empty($files)) {
            echo "  No models found\n\n";
            return;
        }

        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (in_array($name, ['Model', 'Cast', 'Casts'])) {
                continue;
            }

            $class = "App\\Models\\$name";
            echo "  " . $this->color($name, 'yellow') . $this->color(" (alias for $class)", 'gray') . "\n";

            // Try to get table info
            if (class_exists($class)) {
                try {
                    $ref = new \ReflectionClass($class);
                    $props = $ref->getDefaultProperties();

                    if (isset($props['table'])) {
                        echo "    " . $this->color("→ table: {$props['table']}", 'gray') . "\n";
                    }
                } catch (Throwable $e) {
                    // Ignore
                }
            }
        }

        echo "\n";
    }

    /**
     * List routes
     */
    private function listRoutes(): void
    {
        echo "\n" . $this->color("┌─────────────────────────────────────────────────────────────┐", 'cyan') . "\n";
        echo $this->color("│", 'cyan') . $this->color("                         Routes                              ", 'white') . $this->color("│", 'cyan') . "\n";
        echo $this->color("└─────────────────────────────────────────────────────────────┘", 'cyan') . "\n\n";

        $routes = Application::$app->make(Router::class)->getRoutes();

        if (empty($routes)) {
            echo "  " . $this->color("No routes found", 'gray') . "\n\n";
            return;
        }

        // Total route count
        $totalCount = count($routes);

        // Group routes by method for better organization
        $groupedRoutes = [];
        foreach ($routes as $name => $route) {
            $methods = (array) ($route['method'] ?? 'GET');
            if (in_array('*', $methods)) {
                $methods = ['ANY'];
            }

            if (is_int($name)) {
                $name = null;
            }

            $path = $route['path'] ?? '/';
            $callback = isset($route['template']) ? ('View:' . $route['template']) : ($route['callback'] ?? null);

            foreach ($methods as $method) {
                $method = strtoupper($method);
                $groupedRoutes[$method][] = [
                    'path' => $path,
                    'callback' => $callback,
                    'name' => $name
                ];
            }
        }

        // Method colors
        $methodColors = [
            'GET' => 'green',
            'POST' => 'blue',
            'PUT' => 'yellow',
            'PATCH' => 'yellow',
            'DELETE' => 'red',
            'OPTIONS' => 'gray',
            'ANY' => 'gray',
        ];

        // Display routes grouped by method
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'ANY'] as $method) {
            if (!isset($groupedRoutes[$method])) {
                continue;
            }

            $color = $methodColors[$method] ?? 'white';
            $routes = $groupedRoutes[$method];

            echo $this->color("  $method", $color) . $this->color(" (" . count($routes) . ")", 'gray') . "\n";
            echo $this->color("  " . str_repeat('─', 85), 'gray') . "\n";

            foreach ($routes as $route) {
                $path = $route['path'];
                $callback = $route['callback'];
                $name = $route['name'];

                // Format path with parameter highlighting
                $formattedPath = preg_replace('/\{([^}]+)\}/', $this->color('{$1}', 'magenta'), $path);

                echo "  " . $this->color('│', 'gray') . " ";

                // Use the original path length for padding calculation
                $pathLength = strlen($path);
                $padding = max(0, 35 - $pathLength);
                echo $formattedPath . str_repeat(' ', $padding);

                // Show callback if available
                if ($callback) {
                    if (is_string($callback)) {
                        $callbackStr = $callback;
                    } elseif (is_array($callback) && count($callback) === 2) {
                        $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
                        $shortClass = basename(str_replace('\\', '/', $class));
                        $callbackStr = "$shortClass@$callback[1]";
                    } elseif (is_callable($callback)) {
                        $callbackStr = 'Closure';
                    } else {
                        $callbackStr = 'Callable';
                    }
                    echo $this->color($callbackStr, 'cyan');
                }

                // Show route name if available
                if ($name) {
                    echo " " . $this->color("[$name]", 'yellow');
                }

                echo "\n";
            }

            echo "\n";
        }

        echo $this->color("  Total: $totalCount routes", 'white') . "\n\n";
    }

    /**
     * Show configuration
     */
    private function showConfig(): void
    {
        echo "\n" . $this->color("── Configuration ─────────────────────", 'cyan') . "\n";

        // Application settings
        echo "\n" . $this->color("Application:", 'white') . "\n";
        echo "  Name: " . $this->color(config('app.name', 'TinyMVC App'), 'yellow') . "\n";
        echo "  Timezone: " . $this->color(config('app.timezone', 'UTC'), 'yellow') . "\n";
        echo "  Debug: " . $this->color(config('debug', false) ? 'enabled' : 'disabled', 'yellow') . "\n";
        echo "  Language: " . $this->color(config('lang', 'en'), 'yellow') . "\n";

        // Database settings
        echo "\n" . $this->color("Database:", 'white') . "\n";
        echo "  Driver: " . $this->color(config('database.driver', 'mysql'), 'yellow') . "\n";

        if (config('database.driver') === 'sqlite') {
            $dbFile = config('database.file', 'N/A');
            $dbFile = $dbFile !== 'N/A' ? $this->removeRootDir($dbFile) : 'N/A';
            echo "  File: " . $this->color($dbFile, 'yellow') . "\n";
        } else {
            echo "  Host: " . $this->color(config('database.host', 'localhost'), 'yellow') . "\n";
            echo "  Port: " . $this->color((string) config('database.port', '3306'), 'yellow') . "\n";
            echo "  Database: " . $this->color(config('database.name', 'N/A'), 'yellow') . "\n";
            echo "  User: " . $this->color(config('database.user', 'N/A'), 'yellow') . "\n";
        }

        // Mail settings
        echo "\n" . $this->color("Mail:", 'white') . "\n";
        echo "  SMTP: " . $this->color(config('mail.smtp.enabled', false) ? 'enabled' : 'disabled', 'yellow') . "\n";

        if (config('mail.smtp.enabled')) {
            echo "  Host: " . $this->color(config('mail.smtp.host', 'N/A'), 'yellow') . "\n";
            echo "  Port: " . $this->color((string) config('mail.smtp.port', '2525'), 'yellow') . "\n";
            echo "  Encryption: " . $this->color(config('mail.smtp.encryption', 'tls'), 'yellow') . "\n";
        }

        // Paths
        echo "\n" . $this->color("Paths:", 'white') . "\n";
        echo "  Storage: " . $this->color($this->removeRootDir(config('storage_dir', '/storage')), 'yellow') . "\n";
        echo "  Cache: " . $this->color($this->removeRootDir(config('cache_dir', '/cache')), 'yellow') . "\n";
        echo "  Uploads: " . $this->color($this->removeRootDir(config('upload_dir', '/uploads')), 'yellow') . "\n";
        echo "  Views: " . $this->color($this->removeRootDir(config('views_dir', '/views')), 'yellow') . "\n";

        // System info
        echo "\n" . $this->color("System:", 'white') . "\n";
        echo "  PHP: " . $this->color(PHP_VERSION, 'yellow') . "\n";
        echo "  OS: " . $this->color(PHP_OS_FAMILY, 'yellow') . "\n";

        echo "\n";
    }

    /**
     * Remove root directory from path for display
     */
    private function removeRootDir(string $path): string
    {
        return str_replace(root_dir(), '', $path);
    }

    /**
     * Clear screen
     */
    private function clear(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
        $this->printBanner();
    }

    /**
     * Get prompt
     */
    private function getPrompt(): string
    {
        $prefix = $this->buffer ? '...' : '>>>';
        return $this->color($prefix, 'green') . ' ';
    }

    /**
     * Check if exit command
     */
    private function isExitCommand(string $input): bool
    {
        return in_array($input, ['exit', 'quit', 'exit()', 'quit()']);
    }

    /**
     * Exit tinker
     */
    private function exit(): void
    {
        echo "\n" . $this->color("Goodbye! 👋", 'cyan') . "\n\n";
    }
}