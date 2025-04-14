<?php

namespace Spark\Foundation\Console;

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
        StubCreation::make(
            $args['_args'][0] ?? null,
            'What is the name of the controller?',
            [
                'stub' => __DIR__ . '/stubs/controller.stub',
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
                'stub' => __DIR__ . '/stubs/view.stub',
                'destination' => 'resources/views/::subfolder:lowercase/::name:lowercase.php',
            ]
        );
    }
}