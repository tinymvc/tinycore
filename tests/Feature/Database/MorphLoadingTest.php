<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class MorphLoadingTest extends DatabaseScenarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Spark\Database\Schema\Schema::create('scenario_schema', function ($table) {
            $table->id();
            $table->string('subject_type');
            $table->integer('subject_id');
        });

        foreach ([['post', 1], ['user', 2], ['post', 1]] as [$type, $id]) {
            ScenarioActivity::create(['subject_type' => $type, 'subject_id' => $id]);
        }
    }

    private function activities(): \Spark\Database\QueryBuilder
    {
        return ScenarioActivity::morphWith('subject', [
            'post' => ScenarioPost::class,
            'user' => ScenarioUser::class,
        ]);
    }

    public function test_mixed_types(): void
    {
        $items = $this->activities()->orderBy('id')->all();

        $this->assertInstanceOf(ScenarioPost::class, $items[0]->subject);
        $this->assertSame('Alpha', $items[0]->subject->title);
        $this->assertInstanceOf(ScenarioUser::class, $items[1]->subject);
        $this->assertSame('Grace', $items[1]->subject->name);
    }

    public function test_repeated_targets(): void
    {
        $items = $this->activities()->orderBy('id')->all();

        $this->assertSame($items[0]->subject, $items[2]->subject);
    }

    public function test_nested_relations(): void
    {
        $item = ScenarioActivity::morphWith('subject', ['post' => ['class' => ScenarioPost::class, 'relations' => ['comments']]])->findOrFail(1);

        $this->assertTrue($item->subject->relationLoaded('comments'));
        $this->assertCount(2, $item->subject->comments);
    }

    public function test_query_count_per_type(): void
    {
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries) {
            $queries++;
        });
        $this->activities()->all();

        $this->assertSame(3, $queries);
    }

    public function test_unknown_type(): void
    {
        ScenarioActivity::create(['subject_type' => 'unknown', 'subject_id' => 1]);
        $items = $this->activities()->orderBy('id')->all();

        $this->assertFalse($items[3]->relationLoaded('subject'));
    }

    public function test_empty_result(): void
    {
        $this->assertSame([], $this->activities()->whereKey(99)->all());
    }
}
