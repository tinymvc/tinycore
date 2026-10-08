<?php

/** Opt-in DBngin integration run. Only databases created here are removed. */
$root = dirname(__DIR__);
$name = 'spark_test_' . bin2hex(random_bytes(8));
$connections = [];
$status = 0;

$run = static function (string $script) use ($root): int {
    $process = proc_open(
        [PHP_BINARY, $script],
        [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the test runner.');
    }

    stream_copy_to_stream($pipes[1], STDOUT);
    fclose($pipes[1]);

    return proc_close($process);
};

try {
    foreach (['mysql', 'pgsql'] as $driver) {
        $prefix = 'SPARK_TEST_' . strtoupper($driver);
        $adminDsn = getenv($prefix . '_ADMIN_DSN') ?: ($driver === 'mysql'
            ? 'mysql:host=127.0.0.1;port=3306'
            : 'pgsql:host=127.0.0.1;port=5432;dbname=postgres');
        $user = getenv($prefix . '_USER') ?: ($driver === 'mysql' ? 'root' : 'postgres');
        $password = getenv($prefix . '_PASSWORD') ?: '';
        $connection = new PDO($adminDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec("CREATE DATABASE $name");
        $connections[$driver] = $connection;
        $dsn = preg_replace('/;dbname=[^;]*/', '', $adminDsn) . ';dbname=' . $name;

        putenv($prefix . '_DSN=' . $dsn);
        putenv($prefix . '_USER=' . $user);
        putenv($prefix . '_PASSWORD=' . $password);
    }

    putenv('SPARK_TEST_REDIS_HOST=' . (getenv('SPARK_TEST_REDIS_HOST') ?: '127.0.0.1'));
    putenv('SPARK_TEST_DATABASE_DRIVER=sqlite');
    $status = $run('tests/run.php');

    foreach (['mysql', 'pgsql'] as $driver) {
        echo "\nDatabase scenarios: $driver\n";
        putenv('SPARK_TEST_DATABASE_DRIVER=' . $driver);
        $status = max($status, $run('tests/database.php'));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    $status = 1;
} finally {
    foreach ($connections as $driver => $connection) {
        try {
            $connection->exec("DROP DATABASE $name");
            echo "Removed $driver database $name\n";
        } catch (Throwable $exception) {
            fwrite(STDERR, "Could not remove $driver database $name: {$exception->getMessage()}\n");
            $status = 1;
        }
    }
}

exit($status);
