<?php

namespace Tests\Support;

use Spark\Foundation\Application;
use Spark\Testing\ApplicationTestCase;

/** Minimal application: lifecycle tests do not need a database or application services. */
abstract class LifecycleTestCase extends ApplicationTestCase
{
    protected function createApplication(): Application
    {
        return (new Application(dirname(__DIR__)))->withApp(config: [
            'app' => [
                'debug' => false,
                'key' => str_repeat('a', 32),
                'storage_dir' => $this->storagePath,
                'views_dir' => $this->storagePath,
            ]
        ]);
    }
}
