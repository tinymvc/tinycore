<?php

require dirname(__DIR__) . '/vendor/autoload.php';

foreach (require __DIR__ . '/config.php' as $key => $value) {
    if ($value !== null && getenv($key) === false) {
        putenv("$key=$value");
    }
}

putenv('SPARK_TEST_DATABASE_DRIVER=sqlite');

exit((new \Spark\Testing\Runner)->run(__DIR__, array_slice($argv, 1)));
