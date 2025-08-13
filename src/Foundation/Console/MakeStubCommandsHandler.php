<?php

namespace Spark\Foundation\Console;

use Spark\Support\Str;
use Spark\Console\Prompt;
use Spark\Exceptions\NotFoundException;
use Spark\Exceptions\Routing\RouteNotFoundException;
use Spark\Utils\FileManager;

/**
 * Class MakeStubCommandsHandler
 */
class MakeStubCommandsHandler
{

    /**
     * Create a provider stub.
     *
     * @param array $args
     *   The arguments passed to the command.
     *
     * @return void
     */
    public function makeProvider(array $args)
    {
        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the provider?',
            [
                'stub' => __DIR__ . '/stubs/provider.stub',
                'destination' => 'app/Provider/::subfolder:ucfirst/::name:ucfirst.php',
                'replacements' => [
                    '{{ namespace }}' => 'App\Provider::subfolder:namespace',
                    '{{ class }}' => '::name:ucfirst',
                ],
            ]
        );
    }

    /**
     * Create a migration stub.
     *
     * @param array $args
     *   The arguments passed to the command.
     *
     * @return void
     */
    public function makeMigration(array $args)
    {
        // Check if the pivot argument is set and true
        // If so, call the makePivotMigration method
        if ($this->hasFlag($args, ['pivot', 'p'])) {
            return $this->makePivotMigration($args);
        }

        // Otherwise, proceed with the regular migration creation
        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the migration?',
            [
                'stub' => __DIR__ . '/stubs/migration.stub',
                'destination' => 'database/migrations/::subfolder:lowercase/migration_' . date('Y_m_d_His') . '_::name:pluralize:lowercase.php',
                'replacements' => [
                    '{{ table }}' => '::name:pluralize:lowercase',
                ],
            ]
        );

        if ($this->hasFlag($args, ['seeder', 'seed', 's'])) {
            return $this->makeSeeder($args);
        }
    }

    /**
     * Create a seeder stub.
     *
     * @param array $args
     *   The arguments passed to the command.
     *
     * @return void
     */
    public function makeSeeder(array $args)
    {
        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the seeder?',
            [
                'stub' => __DIR__ . '/stubs/seeder.stub',
                'destination' => 'database/migrations/::subfolder:lowercase/seed_' . date('Y_m_d_His') . '_::name:pluralize:lowercase.php',
                'replacements' => [
                    '{{ namespace }}' => 'Database\Seeders::subfolder:namespace',
                    '{{ class }}' => '::name:singularize:ucfirst',
                ],
            ]
        );
    }

    /**
     * Create a pivot migration stub.
     *
     * @param array $args
     *   The arguments passed to the command.
     *
     * @return void
     */
    public function makePivotMigration(array $args)
    {
        $related_table_1 = $args['_args'][0] ?? null;
        $related_table_2 = $args['_args'][1] ?? null;

        $prompt = get(Prompt::class);

        // Get the name from the arguments or prompt the user
        if (!$related_table_1) {
            do {
                $related_table_1 = $prompt->ask('What is the name of the first related table?');
            } while (!$related_table_1);
        }

        // Get the name from the arguments or prompt the user
        if (!$related_table_2) {
            do {
                $related_table_2 = $prompt->ask('What is the name of the second related table?');
            } while (!$related_table_2);
        }
        $related_table_1_singular = Str::snake(Str::singular($related_table_1));
        $related_table_2_singular = Str::snake(Str::singular($related_table_2));

        $related_table_1_plural = Str::snake(Str::plural($related_table_1));
        $related_table_2_plural = Str::snake(Str::plural($related_table_2));

        $tables = [Str::lower($related_table_1_plural), Str::lower($related_table_2_plural)];
        sort($tables);
        $table = implode('_', $tables);

        StubCreation::make(
            $table,
            'What is the name of the pivot migration?',
            [
                'stub' => __DIR__ . '/stubs/migration-pivot.stub',
                'destination' => 'database/migrations/::subfolder:lowercase/migration_' . date('Y_m_d_His') . '_::name.php',
                'replacements' => [
                    '{{ table }}' => '::name',
                    '{{ related_table_1 }}' => $related_table_1_singular,
                    '{{ related_table_2 }}' => $related_table_2_singular,
                    '{{ related_table_plural_1 }}' => $related_table_1_plural,
                    '{{ related_table_plural_2 }}' => $related_table_2_plural,
                ],
            ]
        );
    }

    /**
     * Create a model stub.
     *
     * @param array $args
     *   The arguments passed to the command.
     *
     * @return void
     */
    public function makeModel(array $args)
    {
        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the model?',
            [
                'stub' => __DIR__ . '/stubs/model.stub',
                'destination' => 'app/Models/::subfolder:ucfirst/::name:singularize:ucfirst.php',
                'replacements' => [
                    '{{ namespace }}' => 'App\Models::subfolder:namespace',
                    '{{ class }}' => '::name:singularize:ucfirst',
                    '{{ table }}' => '::name:pluralize:lowercase'
                ],
            ]
        );

        if (!isset($args['_args'][0])) {
            return; // If no model name is provided, exit early
        }

        if ($this->hasFlag($args, ['migration', 'm', 'mc', 'mcr'])) {
            $this->makeMigration($args);
        }

        if ($this->hasFlag($args, ['controller', 'c', 'cr', 'mc', 'mcr'])) {
            if ($this->hasFlag($args, ['cr', 'mcr'])) {
                $args['restful'] = true; // Set the restful flag if 'cr' or 'mcr' is present
            }

            $args['_args'][0] .= 'Controller'; // Append 'Controller' to the model name

            $this->makeController($args);
        }
    }

    /**
     * Create a controller stub.
     *
     * @param array $args
     *   The arguments passed to the command.
     *
     * @return void
     */
    public function makeController(array $args)
    {
        // Always suffix with Controller
        if (!isset($args['_args'][0]) || !preg_match('/Controller$/i', $args['_args'][0])) {
            $args['_args'][0] = ($args['_args'][0] ?? '') . 'Controller';
        }

        $replacements = [
            '{{ namespace }}' => 'App\Http\Controllers::subfolder:namespace',
            '{{ class }}' => '::name:ucfirst',
        ];

        if ($this->hasFlag($args, ['restful', 'resource', 'rest', 'r'])) {
            $stub = __DIR__ . '/stubs/controller/controller-restful.stub';
        } elseif ($this->hasFlag($args, ['view', 'v'])) {
            $stub = __DIR__ . '/stubs/controller/controller-view.stub';
            // View name without the suffix
            $args['routeName'] = strtolower(preg_replace('/Controller$/i', '', $args['_args'][0]));
            $replacements['{{ routeName }}'] = $args['routeName'];
        } else {
            $stub = __DIR__ . '/stubs/controller/controller.stub';
        }

        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the controller?',
            [
                'stub' => $stub,
                'destination' => 'app/Http/Controllers/::subfolder:ucfirst/::name:ucfirst.php',
                'replacements' => $replacements,
            ]
        );

        if ($this->hasFlag($args, ['view', 'v'])) {
            $args['controller'] = true;
            $args['controllerName'] = $args['_args'][0];
            $args['_args'][0] = $args['routeName'];
            $this->makeView($args);
            $this->makeRoute($args);
        }
    }

    /**
     * Create a middleware stub.
     *
     * @param array $args
     *   The arguments passed to the command.
     *
     * @return void
     */
    public function makeMiddleware(array $args)
    {
        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the middleware?',
            [
                'stub' => __DIR__ . '/stubs/middleware.stub',
                'destination' => 'app/Http/Middlewares/::subfolder:ucfirst/::name:ucfirst.php',
                'replacements' => [
                    '{{ namespace }}' => 'App\Http\Middlewares::subfolder:namespace',
                    '{{ class }}' => '::name:ucfirst',
                ],
            ]
        );
    }

    /**
     * Create a view stub.
     *
     * @param array $args
     *   The arguments passed to the command.
     *
     * @return void
     */
    public function makeView(array $args)
    {
        if ($this->hasFlag($args, ['controller'])) {
            $stub = __DIR__ . '/stubs/view/view-controller.blade.stub';
        } else {
            $stub = __DIR__ . '/stubs/view.blade.stub';
        }
        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the view?',
            [
                'stub' => $stub,
                'destination' => 'resources/views/::subfolder:lowercase/::name:lowercase.blade.php',
            ]
        );
    }

    /**
     * Create a view component stub.

     * @param array $args
     *  The arguments passed to the command.
     * @return void
     */
    public function makeViewComponent(array $args)
    {
        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the view component?',
            [
                'stub' => __DIR__ . '/stubs/component.blade.stub',
                'destination' => 'resources/views/components/::subfolder:lowercase/::name:lowercase.blade.php',
            ]
        );
    }

    /**
     * Check if the command has any of the specified flags.
     *
     * @param array $args
     *   The arguments passed to the command.
     * @param array $flags
     *   The flags to check for.
     *
     * @return bool
     *   True if any of the flags are present, false otherwise.
     */
    private function hasFlag(array $args, array $flags): bool
    {
        foreach ($flags as $flag) {
            if (isset($args[$flag]) && $args[$flag]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append a route to web.php.
     *
     * @param array $args
     *  The arguments passed to the command.
     * @return void
     */
    private function makeRoute(array $args)
    {
        $routesPath = $this->base_path('routes/web.php');

        if (!FileManager::isFile($routesPath)) {
            throw new NotFoundException('Routes file not found: ' . $routesPath);
        }

        $route = FileManager::get(__DIR__ . '/stubs/route.stub');
        $route = str_replace('{{ routeName }}', $args['routeName'], $route);
        $route = str_replace('{{ controllerName }}', $args['controllerName'], $route);

        $this->appendUse($routesPath, $args['controllerName']);

        FileManager::append($routesPath, "\n" . $route);
    }

    /**
     * Get the base path of the application.
     *
     * @param string $path An optional path to append to the base path.
     * @return string The full base path.
     */
    private function base_path(string $path = ''): string
    {
        $basePath = rtrim(getcwd(), DIRECTORY_SEPARATOR);

        return $basePath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function appendUse(string $path, string $use)
{
    $content = FileManager::get($path);

    $useLine = "use App\\Http\\Controllers\\" . $use . ";";

    // Vérifie si le use existe déjà (ligne exacte)
    if (preg_match('/^' . preg_quote($useLine, '/') . '\s*$/m', $content)) {
        // Le use existe déjà, ne rien faire
        return;
    }

    // Trouver toutes les occurrences de "use ...;"
    $matches = [];
    preg_match_all('/^use [^;]+;/m', $content, $matches, PREG_OFFSET_CAPTURE);

    $useLineWithNewline = $useLine . "\n";

    if (!empty($matches[0])) {
        $lastMatch = end($matches[0]);
        $insertPos = $lastMatch[1] + strlen($lastMatch[0]);
        $content = substr($content, 0, $insertPos) . "\n" . $useLineWithNewline . substr($content, $insertPos);
    } else {
        if (strpos($content, "<?php") !== false) {
            $insertPos = strpos($content, "<?php") + 5;
            $content = substr($content, 0, $insertPos) . "\n\n" . $useLineWithNewline . substr($content, $insertPos);
        } else {
            $content = $useLineWithNewline . $content;
        }
    }

    FileManager::put($path, $content);
}
}
