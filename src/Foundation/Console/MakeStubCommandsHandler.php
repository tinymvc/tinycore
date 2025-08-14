<?php

namespace Spark\Foundation\Console;

use Spark\Console\Prompt;
use Spark\Support\Str;

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
        // Make sure the name is provided
        $this->askName($args, 'What is the name of the provider?');

        // Resolve the name with the Provider suffix
        $name = $this->resolveSuffix($args['_args'][0], 'Provider');

        // Create the provider stub
        StubCreation::create(
            $name,
            [
                'stub' => __DIR__ . '/stubs/provider.stub',
                'destination' => 'app/Providers/::subfolder:ucfirst/::name:ucfirst.php',
                'replacements' => [
                    '{{ namespace }}' => 'App\Providers::subfolder:namespace',
                    '{{ class }}' => '::name:ucfirst',
                ],
            ]
        );

        // Append the new provider to the providers array
        StubCreation::appendLineToArray(
            root_dir('bootstrap/providers.php'),
            StubCreation::replacement($name, '\App\Providers::subfolder:namespace\::name:ucfirst::class')
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

        $this->askName($args, 'What is the name of the migration?');

        // Otherwise, proceed with the regular migration creation
        StubCreation::create(
            $args['_args'][0],
            [
                'stub' => __DIR__ . '/stubs/database/migration.stub',
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
        $this->askName($args, 'What is the name of the seeder?');

        StubCreation::create(
            $args['_args'][0],
            [
                'stub' => __DIR__ . '/stubs/database/seeder.stub',
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
        $this->askName($args, [
            'What is the name of the first related table?',
            'What is the name of the second related table?',
        ]);

        // Get the related table names from the arguments
        [$related_table_1, $related_table_2] = $args['_args'];

        $related_table_1_singular = Str::snake(Str::singular($related_table_1));
        $related_table_2_singular = Str::snake(Str::singular($related_table_2));

        $related_table_1_plural = Str::snake(Str::plural($related_table_1));
        $related_table_2_plural = Str::snake(Str::plural($related_table_2));

        $tables = [Str::lower($related_table_1_plural), Str::lower($related_table_2_plural)];
        sort($tables);
        $table = implode('_', $tables);

        StubCreation::create(
            $table,
            [
                'stub' => __DIR__ . '/stubs/database/migration-pivot.stub',
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
        $this->askName($args, 'What is the name of the model?');

        StubCreation::create(
            $args['_args'][0],
            [
                'stub' => __DIR__ . '/stubs/database/model.stub',
                'destination' => 'app/Models/::subfolder:ucfirst/::name:singularize:ucfirst.php',
                'replacements' => [
                    '{{ namespace }}' => 'App\Models::subfolder:namespace',
                    '{{ class }}' => '::name:singularize:ucfirst',
                    '{{ table }}' => '::name:pluralize:lowercase'
                ],
            ]
        );

        if ($this->hasFlag($args, ['migration', 'all', 'm', 'mc', 'mcr'])) {
            $this->makeMigration($args);
        }

        if ($this->hasFlag($args, ['seeder', 's', 'all'])) {
            $this->makeSeeder($args);
        }

        if ($this->hasFlag($args, ['controller', 'all', 'c', 'cr', 'mc', 'mcr'])) {
            if ($this->hasFlag($args, ['cr', 'mcr'])) {
                $args['restful'] = true; // Set the restful flag if 'cr' or 'mcr' is present
            }

            $this->makeController($args);
        }

        if ($this->hasFlag($args, ['v', 'view', 'all'])) {
            $this->makeView($args);
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
        if ($this->hasFlag($args, ['restful', 'resource', 'rest', 'r'])) {
            $stub = __DIR__ . '/stubs/controller/restful.stub';
        } else {
            $stub = __DIR__ . '/stubs/controller/general.stub';
        }

        $this->askName($args, 'What is the name of the controller?');

        $name = $this->resolveSuffix($args['_args'][0], 'Controller');

        $hasSubFolder = false;
        foreach (['/', '\\', '.'] as $char) {
            if (strpos($name, $char) !== false) {
                $hasSubFolder = true;
                break;
            }
        }

        StubCreation::create(
            $name,
            [
                'stub' => $stub,
                'destination' => 'app/Http/Controllers/::subfolder:ucfirst/::name:ucfirst.php',
                'replacements' => [
                    '{{ namespace }}' => 'App\Http\Controllers::subfolder:namespace',
                    '{{ class }}' => '::name:ucfirst',
                    '{{ controllerUse }}' => $hasSubFolder ? "\nuse App\Http\Controllers\Controller;\n" : "\n",
                ],
            ]
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
        $this->askName($args, 'What is the name of the middleware?');

        $name = $this->resolveSuffix($args['_args'][0], 'Middleware');

        StubCreation::create(
            $name,
            [
                'stub' => __DIR__ . '/stubs/middleware.stub',
                'destination' => 'app/Http/Middlewares/::subfolder:ucfirst/::name:ucfirst.php',
                'replacements' => [
                    '{{ namespace }}' => 'App\Http\Middlewares::subfolder:namespace',
                    '{{ class }}' => '::name:ucfirst',
                ],
            ]
        );

        // Get the snake case version of the middleware name
        $key = Str::snake($this->removeSuffix($name, 'Middleware'));

        StubCreation::appendLineToArray(
            root_dir('bootstrap/middlewares.php'),
            StubCreation::replacement($name, '\App\Http\Middlewares::subfolder:namespace\::name:ucfirst::class'),
            $key
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
        $this->askName($args, 'What is the name of the view?');

        StubCreation::create(
            $args['_args'][0],
            [
                'stub' => __DIR__ . '/stubs/views/blank.blade.stub',
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
        $this->askName($args, 'What is the name of the view component?');

        StubCreation::create(
            $args['_args'][0],
            [
                'stub' => __DIR__ . '/stubs/views/component.blade.stub',
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
     * Ask for the name(s) of a resource.
     *
     * @param array &$args
     *   The arguments array to populate.
     * @param string|array $questions
     *   The question(s) to ask.
     *
     * @return void
     */
    private function askName(array &$args, string|array $questions): void
    {
        foreach ((array) $questions as $index => $question) {
            if (!isset($args['_args'][$index])) {
                do {
                    $args['_args'][$index] = Prompt::ask($question);
                } while (!isset($args['_args'][$index]));
            }
        }
    }

    /**
     * Resolve the suffix for a given name.
     *
     * @param string $name
     *   The name to resolve the suffix for.
     * @param string $suffix
     *   The suffix to append.
     *
     * @return string
     *   The resolved name with the suffix.
     */
    private function resolveSuffix(string $name, string $suffix): string
    {
        // Remove the suffix if it exists case insensitive
        $name = $this->removeSuffix($name, $suffix);

        // Otherwise, append the suffix to the name
        return "$name$suffix";
    }

    /**
     * Remove a suffix from a name.
     *
     * @param string $name
     *   The name to remove the suffix from.
     * @param string $suffix
     *   The suffix to remove.
     *
     * @return string
     *   The name without the suffix.
     */
    public function removeSuffix(string $name, string $suffix): string
    {
        return preg_replace('/' . preg_quote($suffix, '/') . '$/i', '', $name);
    }
}
