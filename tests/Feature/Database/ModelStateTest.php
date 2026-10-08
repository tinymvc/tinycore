<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class ModelStateTest extends DatabaseScenarioTestCase
{
    public function test_create_persists(): void
    {
        $post = ScenarioPost::create(['title' => 'New']);

        $this->assertSame('New', ScenarioPost::findOrFail($post->id)->title);
        $this->assertTrue($post->wasCreated());
    }

    public function test_fill_is_fluent(): void
    {
        $post = new ScenarioPost;

        $this->assertSame($post, $post->fill(['title' => 'New']));
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_arrayable_create(): void
    {
        $post = ScenarioPost::create(new \Spark\Http\Input(['title' => 'Input']));

        $this->assertSame('Input', ScenarioPost::findOrFail($post->id)->title);
    }

    public function test_arrayable_fill(): void
    {
        $post = new ScenarioPost;
        $post->fill(new \Spark\Http\Input(['title' => 'Input']));

        $this->assertSame('Input', $post->title);
    }

    public function test_first_or_create_existing(): void
    {
        $post = ScenarioPost::firstOrCreate(['title' => 'Alpha'], ['score' => 99]);

        $this->assertSame(1, $post->id);
        $this->assertSame(0, $post->score);
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_first_or_create_missing(): void
    {
        $post = ScenarioPost::firstOrCreate(['title' => 'New'], ['score' => 99]);

        $this->assertSame(99, ScenarioPost::findOrFail($post->id)->score);
    }

    public function test_first_or_new_unsaved(): void
    {
        $post = ScenarioPost::firstOrNew(['title' => 'New'], ['score' => 99]);

        $this->assertFalse($post->exists());
        $this->assertSame(99, $post->score);
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_first_or_new_existing(): void
    {
        $post = ScenarioPost::firstOrNew(['title' => 'Alpha'], ['score' => 99]);

        $this->assertTrue($post->exists());
        $this->assertSame(0, $post->score);
    }

    public function test_create_or_update_existing(): void
    {
        $post = ScenarioPost::createOrUpdate(['title' => 'Alpha', 'score' => 99], ['title']);

        $this->assertSame(1, $post->id);
        $this->assertSame(99, ScenarioPost::findOrFail(1)->score);
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_create_or_update_missing(): void
    {
        $post = ScenarioPost::createOrUpdate(['title' => 'New', 'score' => 99], ['title']);

        $this->assertSame(99, ScenarioPost::findOrFail($post->id)->score);
    }

    public function test_save_updates(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $post->title = 'Changed';

        $this->assertTrue($post->save());
        $this->assertSame('Changed', ScenarioPost::findOrFail(1)->title);
        $this->assertSame(5, ScenarioPost::count());
    }

    public function test_save_tracks_update(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $post->score = 12;
        $post->save();

        $this->assertTrue($post->wasUpdated());
    }

    public function test_fresh_hydration_is_clean(): void
    {
        $this->assertTrue(ScenarioPost::findOrFail(1)->isClean());
    }

    public function test_changed_attribute_is_dirty(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $post->title = 'Changed';

        $this->assertTrue($post->isDirty('title'));
        $this->assertFalse($post->isDirty('score'));
    }

    public function test_original_value(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $post->title = 'Changed';

        $this->assertSame('Alpha', $post->getOriginal('title'));
    }

    public function test_repeated_changes_keep_original(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $post->title = 'Changed';
        $post->title = 'Again';

        $this->assertSame('Alpha', $post->getOriginal('title'));
    }

    public function test_restore_original(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $post->title = 'Changed';
        $post->restoreOriginal();

        $this->assertSame('Alpha', $post->title);
        $this->assertTrue($post->isClean());
    }

    public function test_refresh(): void
    {
        $post = ScenarioPost::findOrFail(1);
        ScenarioPost::whereKey(1)->update(['title' => 'Database']);
        $post->refresh();

        $this->assertSame('Database', $post->title);
    }

    public function test_array_access_read(): void
    {
        $this->assertSame('Alpha', ScenarioPost::findOrFail(1)['title']);
    }

    public function test_array_access_write(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $post['title'] = 'Array';
        $post->save();

        $this->assertSame('Array', ScenarioPost::findOrFail(1)->title);
    }

    public function test_primary_key(): void
    {
        $this->assertSame('id', (new ScenarioPost)->getPrimaryKey());
    }

    public function test_primary_value(): void
    {
        $this->assertSame(1, ScenarioPost::findOrFail(1)->primaryValue());
    }

    public function test_zero_primary_is_present(): void
    {
        $post = (new ScenarioPost)->fill(['id' => 0]);

        $this->assertTrue($post->hasPrimaryValue());
    }

    public function test_empty_primary_is_missing(): void
    {
        $post = (new ScenarioPost)->fill(['id' => '']);

        $this->assertFalse($post->hasPrimaryValue());
    }

    public function test_new_model_doesnt_exist(): void
    {
        $this->assertFalse((new ScenarioPost)->exists());
    }

    public function test_identity_matches(): void
    {
        $this->assertTrue(ScenarioPost::findOrFail(1)->is(ScenarioPost::findOrFail(1)));
    }

    public function test_identity_differs(): void
    {
        $this->assertFalse(ScenarioPost::findOrFail(1)->is(ScenarioPost::findOrFail(2)));
    }

    public function test_array_cast_round_trip(): void
    {
        $post = ScenarioPost::findOrFail(1);

        $this->assertSame(['shared' => true, 'tags' => ['php']], $post->metadata);
    }

    public function test_array_cast_update(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $post->metadata = ['shared' => false];
        $post->save();

        $this->assertSame(['shared' => false], ScenarioPost::findOrFail(1)->metadata);
    }

    public function test_null_cast(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $post->metadata = null;
        $post->save();

        $this->assertNull(ScenarioPost::findOrFail(1)->metadata);
    }

    public function test_integer_cast(): void
    {
        $post = (new ScenarioPost)->fill(['score' => '12']);

        $this->assertSame(12, $post->score);
    }

    public function test_only_serialization(): void
    {
        $this->assertSame(['title' => 'Alpha'], ScenarioPost::findOrFail(1)->only('title')->toArray());
    }

    public function test_except_serialization(): void
    {
        $data = ScenarioPost::findOrFail(1)->except('metadata')->toArray();

        $this->assertFalse(array_key_exists('metadata', $data));
        $this->assertSame('Alpha', $data['title']);
    }

    public function test_missing_attribute_default(): void
    {
        $this->assertSame('fallback', (new ScenarioPost)->get('missing', 'fallback'));
    }

    public function test_copy_is_independent(): void
    {
        $post = ScenarioPost::findOrFail(1);
        $copy = $post->copy();
        $copy->title = 'Copy';

        $this->assertSame('Alpha', $post->title);
    }
}
