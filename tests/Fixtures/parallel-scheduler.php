<?php

// Exercise the real scheduler in a separate process without requiring a ZTS build.
namespace parallel {
    class Runtime
    {
        public static int $started = 0;

        public function run($task): Future
        {
            if (++self::$started === 1) {
                throw new \RuntimeException('startup failure');
            }

            return new Future($task);
        }
    }

    class Future
    {
        public function __construct(private $task) {}

        public function done(): bool
        {
            return true;
        }

        public function value(): mixed
        {
            return ($this->task)();
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    class SchedulerUnderTest extends \Spark\Concurrency
    {
        protected function canUseParallel(): bool
        {
            return true;
        }
    }

    echo json_encode(SchedulerUnderTest::runWithLimit([
        'startup' => fn () => 'not started',
        'failure' => fn () => throw new \RuntimeException('task failure'),
        'success' => fn () => 'completed',
        'last' => fn () => 42,
    ], 1), JSON_THROW_ON_ERROR);
}
