<?php

namespace Spark\Foundation\Console;

use Spark\Support\Str;
use Spark\Console\Prompt;
use Spark\Utils\FileManager;
use Spark\Support\SuffixHelper;
use Spark\Support\ClassManipulation;
use Spark\Exceptions\NotFoundException;

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
        $prompt = get(Prompt::class);
        
        // We ask the question first to be sure that the name is not empty before adding the suffix
        $name = $args['_args'][0] ?? null;
        if (!$name) {
            do {
                $name = $prompt->ask('What is the name of the controller?');
            } while (!$name);
        }

        $args['_args'][0] = SuffixHelper::addSuffix($name, SuffixHelper::CONTROLLER);

        $controllerConfig = $this->getControllerConfig($args);
        
        StubCreation::make(
            $args['_args'][0],
            'What is the name of the controller?',
            [
                'stub' => $controllerConfig['stub'],
                'destination' => 'app/Http/Controllers/::subfolder:ucfirst/::name:ucfirst.php',
                'replacements' => $controllerConfig['replacements'],
            ]
        );

        if ($this->hasFlag($args, ['view', 'v'])) {
            $this->handleViewControllerActions($args, $controllerConfig);
        }
    }

    /**
     * Get controller configuration based on flags.
     *
     * @param array $args The arguments passed to the command
     * @return array Configuration array with stub path and replacements
     */
    private function getControllerConfig(array $args): array
    {
        $replacements = [
            '{{ namespace }}' => 'App\Http\Controllers::subfolder:namespace',
            '{{ class }}' => '::name:ucfirst',
        ];

        if ($this->hasFlag($args, ['restful', 'resource', 'rest', 'r'])) {
            return [
                'stub' => __DIR__ . '/stubs/controller/controller-restful.stub',
                'replacements' => $replacements,
            ];
        } elseif ($this->hasFlag($args, ['view', 'v'])) {
            $routeName = strtolower(SuffixHelper::removeSuffix($args['_args'][0], SuffixHelper::CONTROLLER));
            $replacements['{{ routeName }}'] = $routeName;
            
            return [
                'stub' => __DIR__ . '/stubs/controller/controller-view.stub',
                'replacements' => $replacements,
                'routeName' => $routeName,
                'namespace' => ClassManipulation::buildNamespaceWithSubfolders($args['_args'][0], 'App\Http\Controllers::subfolder:namespace'),
            ];
        } else {
            return [
                'stub' => __DIR__ . '/stubs/controller/controller.stub',
                'replacements' => $replacements,
            ];
        }
    }

    /**
     * Handle additional actions for view controllers (create view and route).
     *
     * @param array $args The arguments passed to the command
     * @param array $controllerConfig The controller configuration
     * @return void
     */
    private function handleViewControllerActions(array &$args, array $controllerConfig): void
    {
        // Set up args for view and route creation
        $args['controller'] = true;
        $args['controllerName'] = $args['_args'][0];
        $args['routeName'] = $controllerConfig['routeName'];
        $args['namespace'] = $controllerConfig['namespace'];
        $args['_args'][0] = $args['routeName'];
        
        // Create view and route
        $this->makeView($args);
        $this->makeRoute($args);

        // Show success messages
        $prompt = get(Prompt::class);
        $prompt->message("A controller and a route have been created", "info");
        $prompt->message(
            "Access to your new route here: <success>/{$args['routeName']}</success>",
            'raw'
        );
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
        $routesPath = root_dir('routes/web.php');

        if (!FileManager::isFile($routesPath)) {
            throw new NotFoundException('Routes file not found: ' . $routesPath);
        }

        $route = FileManager::get(__DIR__ . '/stubs/route.stub');
        $route = str_replace('{{ routeName }}', $args['routeName'], $route);
        $controllerNameParts = explode('/', $args['controllerName']);
        $controllerName = array_pop($controllerNameParts);
        $route = str_replace('{{ controllerName }}', $controllerName, $route);
        $useStatement = 'use ' . $args['namespace'] . '\\' . $controllerName . ';';

        ClassManipulation::appendUse($routesPath, $useStatement);

        FileManager::append($routesPath, "\n" . $route);
    }

}
