<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Database\DB;
use Spark\Database\QueryBuilder;

/** Optional integration tests: use a dedicated test database, never an application database. */
final class ExternalDatabaseTest extends FrameworkTestCase
{
    public function test_mysql_transactions_and_row_locks(): void
    {
        $this->verifyDatabase('mysql', 'SPARK_TEST_MYSQL');
    }

    public function test_postgresql_transactions_and_row_locks(): void
    {
        $this->verifyDatabase('pgsql', 'SPARK_TEST_PGSQL');
    }

    private function verifyDatabase(string $driver, string $prefix): void
    {
        $dsn = getenv($prefix . '_DSN');

        if (!$dsn) {
            $this->markTestSkipped("Set {$prefix}_DSN to run against a dedicated {$driver} test database.");
        }

        $db = new DB([
            'driver' => $driver,
            'dsn' => $dsn,
            'user' => getenv($prefix . '_USER') ?: '',
            'password' => getenv($prefix . '_PASSWORD') ?: '',
        ]);
        $table = 'spark_test_' . bin2hex(random_bytes(8));
        $query = fn () => (new QueryBuilder($db))->table($table);
        $db->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, name VARCHAR(80) NOT NULL)");

        try {
            $query()->insert(['id' => 1, 'name' => 'before']);
            $db->transaction(function () use ($query): void {
                $row = $query()->where('id', 1)->lockForUpdate()->first();
                $this->assertSame('before', $row->name);
                $this->assertSame(1, $query()->where('id', 1)->update(['name' => 'after']));
            });
            $this->assertSame('after', $query()->where('id', 1)->first()->name);
            $this->assertThrows(\RuntimeException::class, function () use ($db, $query): void {
                $db->transaction(function () use ($query): void {
                    $query()->insert(['id' => 2, 'name' => 'rolled back']);
                    throw new \RuntimeException('Rollback deliberately.');
                });
            });
            $this->assertSame(1, $query()->count());
        } finally {
            $db->exec("DROP TABLE {$table}");
        }
    }
}
