<?php

// Local DBngin defaults. Existing environment variables take precedence.
// Use null to leave an optional setting unset. Never put production credentials here.
return [
    'SPARK_TEST_MYSQL_ADMIN_DSN' => 'mysql:host=127.0.0.1;port=3306',
    'SPARK_TEST_MYSQL_USER' => 'root',
    'SPARK_TEST_MYSQL_PASSWORD' => '',

    'SPARK_TEST_PGSQL_ADMIN_DSN' => 'pgsql:host=127.0.0.1;port=5432;dbname=postgres',
    'SPARK_TEST_PGSQL_USER' => 'postgres',
    'SPARK_TEST_PGSQL_PASSWORD' => '',

    'SPARK_TEST_REDIS_HOST' => '127.0.0.1',
    'SPARK_TEST_REDIS_PORT' => 6379,
    'SPARK_TEST_REDIS_SOCKET' => null,
    'SPARK_TEST_REDIS_PASSWORD' => null,
];
