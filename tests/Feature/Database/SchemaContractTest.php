<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

use Spark\Database\Schema\Schema;

final class SchemaContractTest extends DatabaseScenarioTestCase
{
    public function test_has_table(): void
    {
        $this->assertTrue(Schema::hasTable('scenario_posts'));
    }

    public function test_missing_table(): void
    {
        $this->assertFalse(Schema::hasTable('scenario_missing'));
    }

    public function test_has_column(): void
    {
        $this->assertTrue(Schema::hasColumn('scenario_posts', 'title'));
    }

    public function test_missing_column(): void
    {
        $this->assertFalse(Schema::hasColumn('scenario_posts', 'missing'));
    }

    public function test_has_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('scenario_posts', ['title', 'score']));
    }

    public function test_has_columns_missing(): void
    {
        $this->assertFalse(Schema::hasColumns('scenario_posts', ['title', 'missing']));
    }

    public function test_column_listing(): void
    {
        $this->assertTrue(in_array('metadata', Schema::getColumnListing('scenario_posts'), true));
    }

    public function test_nullable_accepts_null(): void
    {
        $id = query('scenario_posts')->insert(['title' => 'Nullable', 'user_id' => null]);

        $this->assertNull(ScenarioPost::findOrFail($id)->user_id);
    }

    public function test_required_rejects_null(): void
    {
        $this->assertThrows(\PDOException::class, fn () => query('scenario_posts')->insert(['title' => null]));
    }

    public function test_default_value(): void
    {
        $id = query('scenario_posts')->insert(['title' => 'Defaults']);

        $this->assertSame('draft', ScenarioPost::findOrFail($id)->status);
    }

    public function test_add_column(): void
    {
        Schema::table('scenario_users', fn ($table) => $table->string('nickname')->nullable());

        $this->assertTrue(Schema::hasColumn('scenario_users', 'nickname'));
        $this->assertSame(3, ScenarioUser::count());
    }

    public function test_drop_column(): void
    {
        Schema::table('scenario_posts', fn ($table) => $table->dropColumn('metadata'));

        $this->assertFalse(Schema::hasColumn('scenario_posts', 'metadata'));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_rename_table(): void
    {
        Schema::create('scenario_schema', fn ($table) => $table->id());
        Schema::rename('scenario_schema', 'scenario_renamed');

        $this->assertTrue(Schema::hasTable('scenario_renamed'));
        $this->assertFalse(Schema::hasTable('scenario_schema'));
    }

    public function test_drop_table(): void
    {
        Schema::create('scenario_schema', fn ($table) => $table->id());
        Schema::drop('scenario_schema');

        $this->assertFalse(Schema::hasTable('scenario_schema'));
    }

    public function test_drop_missing_is_safe(): void
    {
        Schema::dropIfExists('scenario_schema');

        $this->assertFalse(Schema::hasTable('scenario_schema'));
    }

    public function test_unique_constraint(): void
    {
        Schema::create('scenario_schema', function ($table) {
            $table->id();
            $table->string('code')->unique();
        });
        query('scenario_schema')->insert(['code' => 'one']);

        $this->assertThrows(\PDOException::class, fn () => query('scenario_schema')->insert(['code' => 'one']));
    }
}
