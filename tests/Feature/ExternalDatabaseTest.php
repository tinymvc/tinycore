<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';
require_once dirname(__DIR__) . '/Support/DatabaseCacheRegressionChecks.php';

use Spark\Database\DB;
use Spark\Database\QueryBuilder;
use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

/** Optional integration tests: use a dedicated test database, never an application database. */
final class ExternalDatabaseTest extends FrameworkTestCase
{
    use DatabaseCacheRegressionChecks;

    public function test_mysql_cache_identity_and_lock_transactions(): void
    {
        $this->verifyDatabase('mysql', 'SPARK_TEST_MYSQL', $this->verifyCacheAndLocks(...));
    }

    public function test_postgresql_cache_identity_and_lock_transactions(): void
    {
        $this->verifyDatabase('pgsql', 'SPARK_TEST_PGSQL', $this->verifyCacheAndLocks(...));
    }

    public function test_mysql_transactions_and_row_locks(): void
    {
        $this->verifyDatabase('mysql', 'SPARK_TEST_MYSQL');
    }

    public function test_postgresql_transactions_and_row_locks(): void
    {
        $this->verifyDatabase('pgsql', 'SPARK_TEST_PGSQL');
    }

    public function test_mysql_prefixes_and_expressions(): void
    {
        $this->verifyDatabase('mysql', 'SPARK_TEST_MYSQL', $this->verifyPrefixesAndExpressions(...));
    }

    public function test_postgresql_prefixes_and_expressions(): void
    {
        $this->verifyDatabase('pgsql', 'SPARK_TEST_PGSQL', $this->verifyPrefixesAndExpressions(...));
    }

    private function verifyPrefixesAndExpressions(DB $connection): void
    {
        $previous = app(DB::class);
        $db = new DB([
            'driver' => $connection->getDriver(),
            'dsn' => $connection->getConfig('dsn'),
            'user' => $connection->getConfig('user'),
            'password' => $connection->getConfig('password'),
            'prefix' => 'spark_' . bin2hex(random_bytes(4)) . '_',
        ]);
        $this->app->instance(DB::class, $db);

        try {
            Schema::create('parents', function (Blueprint $table) {
                $table->id();
                $table->integer('points')->index();
                $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            });
            Schema::create('children', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')->constrained('parents')->cascadeOnDelete();
            });
            $this->assertTrue(Schema::hasTable('parents'));
            $this->assertTrue(Schema::hasColumn('parents', 'points'));
            $id = query('parents')->insert(['points' => DB::raw('3 + 4')]);
            query('children')->insert(['parent_id' => $id]);
            $this->assertSame(7, (int) query('parents as p')->join('children as c', 'c.parent_id', '=', 'p.id')->value('p.points'));
            $this->assertSame(1, query('parents')->where('id', $id)->update(['points' => DB::raw('points + 1')]));
            $this->assertSame(8, (int) query('parents')->orderBy('parents.id')->cursorPaginate(1)->items()[0]->points);
            $this->assertSame(8, (int) $db->run('SELECT points FROM ' . $db->wrapTable('parents') . ' WHERE id = ?', [$id])[0]['points']);
            Schema::table('parents', fn(Blueprint $table) => $table->dropIndex(['points']));
            query('parents')->where('id', $id)->delete();
            $this->assertSame(0, query('children')->count());
            Schema::drop('children');
            Schema::rename('parents', 'renamed');
            $this->assertTrue(Schema::hasTable('renamed'));
            $this->verifyQualifiedPrefixSchema($db);
        } finally {
            try {
                Schema::dropIfExists('children');
                Schema::dropIfExists('parents');
                Schema::dropIfExists('renamed');
            } finally {
                $this->app->instance(DB::class, $previous);
                Schema::getConnection();
            }
        }
    }

    private function verifyQualifiedPrefixSchema(DB $db): void
    {
        $schema = $db->isPostgreSQL() ? 'spark_namespace_' . bin2hex(random_bytes(4))
            : $db->query('SELECT DATABASE()')->fetchColumn();
        if ($db->isPostgreSQL()) {
            $db->exec('CREATE SCHEMA "' . $schema . '"');
        }
        $parent = "$schema.namespace_parents";
        $child = "$schema.namespace_children";
        $renamed = "$schema.namespace_renamed";
        $long = $schema . '.' . str_repeat('x', 63 - strlen($db->getTablePrefix()));
        try {
            Schema::create($parent, function (Blueprint $table) {
                $table->integer('id')->primary();
                $table->integer('score')->index();
            });
            Schema::create($child, function (Blueprint $table) use ($parent) {
                $table->integer('parent_id');
                $table->foreign('parent_id')->references('id')->on($parent);
            });
            $this->assertTrue(Schema::hasColumns($parent, ['id', 'score']));
            query($parent)->insert(['id' => 1, 'score' => 7]);
            query($child)->insert(['parent_id' => 1]);
            $this->assertSame(1, query($parent)->count());
            $this->assertSame(7, (int) query($parent)->value("$parent.score"));
            Schema::table($child, fn(Blueprint $table) => $table->dropForeign(['parent_id']));
            Schema::table($parent, fn(Blueprint $table) => $table->dropIndex(['score']));
            Schema::table($parent, fn(Blueprint $table) => $table->dropPrimary());
            Schema::rename($parent, $renamed);
            $this->assertTrue(Schema::hasTable($renamed));
            $this->assertFalse(Schema::hasTable($parent));
            Schema::create($long, fn(Blueprint $table) => $table->integer('id')->primary());
            Schema::table($long, fn(Blueprint $table) => $table->dropPrimary());
            query($long)->insert([['id' => 1], ['id' => 1]]);
            $this->assertSame(2, query($long)->count());
        } finally {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
            Schema::dropIfExists($renamed);
            Schema::dropIfExists($long);
            if ($db->isPostgreSQL()) {
                $db->exec('DROP SCHEMA "' . $schema . '"');
            }
        }
    }

    private function verifyDatabase(string $driver, string $prefix, ?callable $verify = null): void
    {
        $dsn = getenv($prefix . '_DSN');
        $adminDsn = getenv($prefix . '_ADMIN_DSN');

        if (!$dsn && !$adminDsn) {
            $this->markTestSkipped("Configure {$prefix}_ADMIN_DSN in tests/config.php to test {$driver}.");
        }

        $admin = null;
        $database = null;

        if (!$dsn) {
            $admin = new PDO($adminDsn, getenv($prefix . '_USER') ?: '', getenv($prefix . '_PASSWORD') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $database = 'spark_test_' . bin2hex(random_bytes(8));
            $admin->exec("CREATE DATABASE $database");
            $dsn = preg_replace('/;dbname=[^;]*/', '', $adminDsn) . ';dbname=' . $database;
        }

        try {
            $this->verifyConnection($driver, $prefix, $dsn, $verify);
        } finally {
            // verifyConnection() has returned, releasing its PDO connection before DROP DATABASE.
            if ($database !== null) {
                $admin->exec("DROP DATABASE $database");
            }
        }
    }

    private function verifyConnection(string $driver, string $prefix, string $dsn, ?callable $verify): void
    {
        $db = new DB([
            'driver' => $driver,
            'dsn' => $dsn,
            'user' => getenv($prefix . '_USER') ?: '',
            'password' => getenv($prefix . '_PASSWORD') ?: '',
        ]);

        if ($verify !== null) {
            $verify($db);
            return;
        }

        $table = 'spark_test_' . bin2hex(random_bytes(8));
        $query = fn() => (new QueryBuilder($db))->table($table);
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

    private function verifyCacheAndLocks(DB $db): void
    {
        $previousDb = app(DB::class);
        $previousConfig = config('database');
        $table = 'spark_cache_' . bin2hex(random_bytes(8));
        $lockTable = 'spark_locks_' . bin2hex(random_bytes(8));
        $this->app->instance(DB::class, $db);
        $this->app->setConfig('database', ['driver' => $db->getDriver()]);

        try {
            Schema::create($table, function (Blueprint $table): void {
                $table->string('key');
                $table->string('group');
                $table->longText('data');
                $table->bigInteger('expiration')->default(0);
                $table->primary(['group', 'key']);
            });

            Schema::create($lockTable, function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->bigInteger('expiration');
            });

            $config = ['table' => $table, 'lock_table' => $lockTable];
            $this->checkCacheKeyIdentity($config);
            $this->checkLockKeyAndOwnerIdentity($config);
            $this->checkKeyCollation($config);
            $this->checkLockTransactionIsolation($config);
        } finally {
            try {
                Schema::dropIfExists($lockTable);
                Schema::dropIfExists($table);
            } finally {
                $this->app->instance(DB::class, $previousDb);
                $this->app->setConfig('database', $previousConfig);
                Schema::getConnection();
            }
        }
    }
}
