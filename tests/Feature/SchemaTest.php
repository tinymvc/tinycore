<?php

require_once dirname(__DIR__) . '/Support/DatabaseTestCase.php';

use Spark\Database\Schema\Schema;
use Spark\Database\Schema\Blueprint;
use Spark\Database\Model;
use Spark\Facades\DB;

final class SchemaTest extends DatabaseTestCase
{
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
