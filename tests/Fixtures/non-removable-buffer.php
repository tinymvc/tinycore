<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('APP_ENV=testing');
$app = new Spark\Foundation\Application(__DIR__);
ob_start(null, 0, PHP_OUTPUT_HANDLER_FLUSHABLE);
echo 'buffered';
(new ReflectionMethod($app, 'finishRequest'))->invoke($app);
echo ':finished';
