<?php

require_once dirname(__DIR__) . '/Support/DatabaseTestCase.php';

use Spark\Database\Schema\Schema;
use Spark\Database\Schema\Blueprint;
use Spark\Facades\DB;

final class SchemaTest extends DatabaseTestCase
{
    public function test_grammar_normalizes_supported_driver_names(): void
    {
        foreach (DB::SUPPORTED_DRIVERS as $driver) {
            $grammar = new \Spark\Database\Schema\Grammar(strtoupper($driver));
            $this->assertSame($driver, $grammar->getDriver());
            $this->assertSame(
                $driver === DB::DRIVER_MYSQL ? '`example`' : '"example"',
                $grammar->getWrapper()->wrapTable('example'),
            );
            $this->assertSame($driver === DB::DRIVER_MYSQL, $grammar->isMySQL());
            $this->assertSame($driver === DB::DRIVER_SQLITE, $grammar->isSQLite());
            $this->assertSame($driver === DB::DRIVER_PGSQL, $grammar->isPostgreSQL());
        }

        $this->assertThrows(
            \Spark\Database\Schema\Exceptions\UnsupportedDatabaseDriverException::class,
            fn() => new \Spark\Database\Schema\Grammar(DB::DRIVER_SQLSRV),
        );
    }

    public function test_binary_column_types_and_modifiers(): void
    {
        $grammar = Schema::getGrammar();
        $property = new \ReflectionProperty(Schema::class, 'grammar');

        try {
            foreach (['mysql', 'sqlite', 'pgsql'] as $driver) {
                $property->setValue(null, new \Spark\Database\Schema\Grammar($driver));
                $table = new Blueprint('binary_types');
                $fallback = $driver === 'pgsql' ? 'BYTEA' : 'BLOB';
                $columns = [
                    [$table->binary('legacy'), 'BLOB'],
                    [$table->binary('variable', 32), 'VARBINARY(32)'],
                    [$table->binary('fixed', 16, fixed: true), 'BINARY(16)'],
                    [$table->binary('without_length', fixed: true), 'BLOB'],
                    [$table->binary('zero_length', 0), 'BLOB'],
                    [$table->binary('key', length: 255), 'VARBINARY(255)'],
                    [$table->binary('custom_key', length: 64), 'VARBINARY(64)'],
                ];

                foreach ($columns as [$column, $mysqlType]) {
                    $name = Schema::getGrammar()->getWrapper()->wrapColumn($column->getName());
                    $type = $driver === 'mysql' ? $mysqlType : $fallback;
                    $this->assertSame("$name $type NOT NULL", $column->toSql());
                }

                $sql = $table->binary('optional', length: 255)->nullable()->toSql();
                $this->assertStringContainsString(' NULL', $sql);
                $this->assertFalse(str_contains($sql, 'NOT NULL'));
            }
        } finally {
            $property->setValue(null, $grammar);
        }
    }

    public function test_binary_columns_preserve_bytes_and_key_identity(): void
    {
        Schema::create('binary_values', function (Blueprint $table) {
            $table->binary('key', length: 255)->primary();
            $table->binary('payload');
            $table->binary('fixed', 16, fixed: true)->nullable();
        });

        $payload = "\0\xffbytes ";
        foreach (['key', 'Key', 'key '] as $key) {
            query('binary_values')->insert(['key' => $key, 'payload' => $payload]);
            $this->assertSame($payload, query('binary_values')->where('key', $key)->value('payload'));
        }

        $this->assertSame(3, query('binary_values')->count());
        $this->assertThrows(\PDOException::class, fn() => query('binary_values')->insert(['key' => 'key', 'payload' => 'duplicate']));
    }

    public function test_column_nullability_defaults(): void
    {
        $grammar = Schema::getGrammar();
        $property = new \ReflectionProperty(Schema::class, 'grammar');

        try {
            foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
                $property->setValue(null, new \Spark\Database\Schema\Grammar($driver));
                $table = new Blueprint('nullability');
                $this->assertStringContainsString('NOT NULL', $table->string('title')->toSql());
                $this->assertStringContainsString('NOT NULL', $table->integer('number')->toSql());
                $this->assertFalse(str_contains($table->text('notes')->nullable()->toSql(), 'NOT NULL'));
                $this->assertStringContainsString('NOT NULL', $table->boolean('active')->nullable()->nullable(false)->toSql());
                $this->assertFalse(str_contains($table->date('date')->required(false)->toSql(), 'NOT NULL'));
                $this->assertSame(1, substr_count($table->string('code')->nullable()->required()->toSql(), 'NOT NULL'));

                if ($driver === 'mysql') {
                    $this->assertStringContainsString('BIGINT UNSIGNED NOT NULL', $table->unsignedBigInteger('owner_id')->toSql());
                    $this->assertStringContainsString(
                        'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',
                        $table->string('key')->charset('utf8mb4')->collation('utf8mb4_bin')->toSql(),
                    );
                }
            }
        } finally {
            $property->setValue(null, $grammar);
        }

        Schema::create('nullability', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->integer('number')->nullable(false)->default(0);
            $table->rememberToken();
            $table->softDeletes();
            $table->nullableTimestamps();
        });
        $columns = array_column(DB::query('PRAGMA table_info(nullability)')->fetchAll(\PDO::FETCH_ASSOC), null, 'name');

        foreach (['title', 'number'] as $name) {
            $this->assertSame(1, (int) $columns[$name]['notnull']);
        }

        foreach (['notes', 'remember_token', 'deleted_at', 'created_at', 'updated_at'] as $name) {
            $this->assertSame(0, (int) $columns[$name]['notnull']);
        }

        foreach (['INSERT INTO nullability (title) VALUES (NULL)', 'INSERT INTO nullability DEFAULT VALUES'] as $sql) {
            $rejected = false;

            try {
                DB::getPdo()->exec($sql);
            } catch (\PDOException) {
                $rejected = true;
            }

            $this->assertTrue($rejected);
        }

        DB::getPdo()->exec("INSERT INTO nullability (title, notes) VALUES ('', NULL)");
        $this->assertSame(1, query('nullability')->count());
        Schema::table('nullability', function (Blueprint $table) {
            $table->integer('revision')->default(1);
            $table->string('description')->nullable();
        });
        $this->assertSame(1, (int) query('nullability')->value('revision'));
        $this->assertNull(query('nullability')->value('description'));
    }
}
