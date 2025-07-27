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
        if ($this->hasFlag($args, ['restful', 'resource', 'rest', 'r'])) {
            $stub = __DIR__ . '/stubs/controller-restful.stub';
        } else {
            $stub = __DIR__ . '/stubs/controller.stub';
        }

        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the controller?',
            [
                'stub' => $stub,
                'destination' => 'app/Http/Controllers/::subfolder:ucfirst/::name:ucfirst.php',
                'replacements' => [
                    '{{ namespace }}' => 'App\Http\Controllers::subfolder:namespace',
                    '{{ class }}' => '::name:ucfirst',
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
        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the view?',
            [
                'stub' => __DIR__ . '/stubs/view.blade.stub',
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
}
