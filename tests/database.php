<?php

use Spark\Console\Prompt;

require dirname(__DIR__) . '/vendor/autoload.php';

foreach (require __DIR__ . '/config.php' as $key => $value) {
    if ($value !== null && getenv($key) === false) {
        putenv($key . '=' . $value);
    }
}

use Spark\Testing\Runner;

$arguments = array_slice($argv, 1);
$directory = __DIR__ . '/Feature/Database';
$phase = getenv('SPARK_TEST_DATABASE_PHASE');

// Separate processes release their connections before the parent drops the databases.
if (in_array($phase, ['sqlite', 'mysql', 'pgsql'], true)) {
    exit((new Runner)->run($directory, $arguments));
}

if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    echo "Usage: php tests/database.php [--filter text] [--list-tests]\n\n";
    echo "Run database scenarios on SQLite, MySQL and PostgreSQL. Local DBngin\n";
    echo "defaults are used; temporary databases are created and removed automatically.\n";

    exit(0);
}

if (in_array('--list-tests', $arguments, true)) {
    exit((new Runner)->run($directory, $arguments));
}

// Validate the selection before creating any databases.
ob_start();
$status = (new Runner)->run($directory, [...$arguments, '--list-tests']);
$selection = ob_get_clean();

if ($status !== 0) {
    echo $selection;

    exit($status);
}

$name = 'spark_test_' . bin2hex(random_bytes(8));
$connections = [];
$status = 0;

$run = static function (string $phase, array $options): int {
    $environment = getenv();
    $environment['SPARK_TEST_DATABASE_PHASE'] = $phase;
    $environment['SPARK_TEST_DATABASE_DRIVER'] = $phase;

    Prompt::newline();
    Prompt::info("Running $phase database scenarios");

    $terminal = function_exists('stream_isatty') && stream_isatty(STDOUT);

    $process = proc_open(
        [PHP_BINARY, __FILE__, ...$options],
        [0 => STDIN, 1 => $terminal ? STDOUT : ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        dirname(__DIR__),
        $environment,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the test runner.');
    }

    if (!$terminal) {
        stream_copy_to_stream($pipes[1], STDOUT);
        fclose($pipes[1]);
    }

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

    foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
        $status = max($status, $run($driver, $arguments));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Test setup failed: {$exception->getMessage()}\nStart the local SQL services or use tests/run.php for SQLite.\n");
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

Prompt::newline();

$status === 0 ? Prompt::success("Test run: PASS") : Prompt::error("Test run: FAIL");

exit($status);
