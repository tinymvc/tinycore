<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class CustomKeyTest extends DatabaseScenarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Spark\Database\Schema\Schema::create('scenario_schema', function ($table) {
            $table->string('code')->primary();
            $table->string('name');
        });

        foreach (['a' => 'Alpha', 'b' => 'Beta', '0' => 'Zero'] as $code => $name) {
            query('scenario_schema')->insert(['code' => (string) $code, 'name' => $name]);
        }
    }

    public function test_find_string(): void
    {
        $this->assertSame('Alpha', ScenarioCustomKey::findOrFail('a')->name);
    }

    public function test_pivot_sync_and_toggle_preserve_string_keys(): void
    {
        \Spark\Database\Schema\Schema::create('scenario_custom_pivot', function ($table) {
            $table->string('parent_code');
            $table->string('related_code');
            $table->unique(['parent_code', 'related_code']);
        });
        $relation = new \Spark\Database\Relation\BelongsToMany(
            related: ScenarioCustomKey::class,
            table: 'scenario_custom_pivot',
            foreignPivotKey: 'parent_code',
            relatedPivotKey: 'related_code',
            parentKey: 'code',
            relatedKey: 'code',
            model: ScenarioCustomKey::findOrFail('a'),
        );
        $relation->attach(['b']);
        $this->assertSame(['attached' => [], 'detached' => []], $relation->sync(['b']));
        $this->assertSame(['attached' => [], 'detached' => ['b']], $relation->toggle(['b']));
        $this->assertSame(0, query('scenario_custom_pivot')->count());
        $relation->attach(['b']);
        $this->assertSame(['attached' => [], 'detached' => ['b']], $relation->sync([]));
        $this->assertSame(0, query('scenario_custom_pivot')->count());
        $this->assertSame(['attached' => [0], 'detached' => []], $relation->sync(['0']));
        $this->assertSame(1, query('scenario_custom_pivot')->count());
        $this->assertSame(['attached' => [], 'detached' => [0]], $relation->toggle(['0']));
        $this->assertSame(0, query('scenario_custom_pivot')->count());
    }

    public function test_find_zero_string(): void
    {
        $this->assertSame('Zero', ScenarioCustomKey::findOrFail('0')->name);
    }

    public function test_where_key(): void
    {
        $this->assertSame('Beta', ScenarioCustomKey::whereKey('b')->first()->name);
    }

    public function test_where_keys(): void
    {
        $this->assertSame(2, ScenarioCustomKey::whereKey(['a', 'b'])->count());
    }

    public function test_where_not_key(): void
    {
        $this->assertSame(2, ScenarioCustomKey::whereNotKey('a')->count());
    }

    public function test_empty_keys(): void
    {
        $this->assertSame(0, ScenarioCustomKey::whereKey([])->count());
    }

    public function test_empty_exclusions(): void
    {
        $this->assertSame(3, ScenarioCustomKey::whereNotKey([])->count());
    }

    public function test_aliased_find(): void
    {
        $this->assertSame('Alpha', ScenarioCustomKey:: as('k')->findOrFail('a')->name);
    }

    public function test_aliased_exclusion(): void
    {
        $this->assertSame(2, ScenarioCustomKey:: as('k')->whereNotKey('a')->count());
    }

    public function test_save(): void
    {
        $model = ScenarioCustomKey::findOrFail('a');
        $model->name = 'Saved';
        $model->save();

        $this->assertSame('Saved', ScenarioCustomKey::findOrFail('a')->name);
        $this->assertSame(3, ScenarioCustomKey::count());
    }

    public function test_delete(): void
    {
        $this->assertTrue(ScenarioCustomKey::findOrFail('a')->remove());
        $this->assertNull(ScenarioCustomKey::find('a'));
        $this->assertSame(2, ScenarioCustomKey::count());
    }

    public function test_bulk_destroy(): void
    {
        $this->assertSame(2, ScenarioCustomKey::destroy(['a', 'b']));
        $this->assertSame('Zero', ScenarioCustomKey::first()->name);
    }

    public function test_aliased_update(): void
    {
        $this->assertSame(1, ScenarioCustomKey:: as('k')->whereKey('a')->update(['name' => 'Changed']));
        $this->assertSame('Changed', ScenarioCustomKey::findOrFail('a')->name);
    }

    public function test_aliased_delete(): void
    {
        $this->assertSame(1, ScenarioCustomKey:: as('k')->whereKey('a')->delete());
        $this->assertSame(2, ScenarioCustomKey::count());
    }

    public function test_identity(): void
    {
        $this->assertTrue(ScenarioCustomKey::findOrFail('0')->is(ScenarioCustomKey::findOrFail('0')));
    }

    public function test_primary_value(): void
    {
        $this->assertSame('a', ScenarioCustomKey::findOrFail('a')->primaryValue());
    }

    public function test_create(): void
    {
        $model = ScenarioCustomKey::create(['code' => 'new', 'name' => 'Created']);

        $this->assertSame('new', $model->primaryValue());
        $this->assertSame('Created', ScenarioCustomKey::findOrFail('new')->name);
    }

    public function test_first_or_create(): void
    {
        $model = ScenarioCustomKey::firstOrCreate(['code' => 'new'], ['name' => 'Created']);

        $this->assertSame('new', $model->primaryValue());
        $this->assertSame('Created', ScenarioCustomKey::findOrFail('new')->name);
    }
}
