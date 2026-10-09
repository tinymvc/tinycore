<?php

require_once dirname(__DIR__) . '/Support/DatabaseTestCase.php';

use Spark\Database\Schema\Schema;
use Spark\Database\Schema\Blueprint;
use Spark\Database\Model;
use Spark\Facades\DB;

final class ModelRelationsTest extends DatabaseTestCase
{
    public function test_arrayable_relation_attributes(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('user_id');
            $table->integer('views')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        query('users')->insert(['name' => 'Ada']);
        $user = DatabaseFixtureUser::findOrFail(1);
        $factories = [
            'array' => fn(array $data) => $data,
            'input' => fn(array $data) => new \Spark\Http\Input($data),
            'arrayable' => fn(array $data) => new class ($data) implements \Spark\Contracts\Support\Arrayable {
            public function __construct(private array $data)
                {}
                public function toArray(): array
                {
                    return $this->data;
                }
                },
        ];
        foreach ($factories as $label => $make) {
            // The parent overrides a supplied foreign key without mutating the input.
            $original = ['title' => $label, 'user_id' => 999];
            $attributes = $make($original);
            $post = $user->posts()->create($attributes);
            $stored = DatabaseFixturePost::findOrFail($post->id);
            $this->assertSame($label, $stored->title);
            $this->assertSame(1, (int) $stored->user_id);

            $found = $user->posts()->firstOrCreate($attributes, ['views' => 99]);
            $this->assertSame($post->id, $found->id);
            $this->assertSame(0, (int) DatabaseFixturePost::findOrFail($post->id)->views);
            $updated = $user->posts()->createOrUpdate($attributes, ['views' => 2]);
            $this->assertSame($post->id, $updated->id);
            $this->assertSame(2, (int) DatabaseFixturePost::findOrFail($post->id)->views);
            $this->assertSame($original, is_array($attributes) ? $attributes : $attributes->toArray());

            foreach (['firstOrCreate', 'createOrUpdate'] as $method) {
                $data = ['title' => "$label-$method"];
                $input = $make($data);
                $created = $user->posts()->$method($input, ['views' => 3]);
                $stored = DatabaseFixturePost::findOrFail($created->id);
                $this->assertSame($data['title'], $stored->title);
                $this->assertSame(1, (int) $stored->user_id);
                $this->assertSame(3, (int) $stored->views);
                $this->assertSame($data, is_array($input) ? $input : $input->toArray());
            }
        }
        $this->assertSame(9, $user->posts()->count());
    }

    public function test_database_workflows(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->required();
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->required();
            $table->unsignedBigInteger('user_id')->required();
            $table->integer('views')->default(0);
            $table->timestamps();
        });
        // Fluent modifiers must target the named column, and the last nullability wins.
        Schema::create('docs_foreign', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('first_id')->required();
            $table->string('unrelated')->required();
            $table->foreign('first_id')->nullable()->references('id')->on('users');
            $table->foreignId('second_id')->constrained('users');
            $table->foreignId('third_id')->nullable()->constrained('users');
            $table->string('code')->required()->nullable()->required()->index();
        });
        $columns = DB::query('PRAGMA table_info(docs_foreign)')->fetchAll(\PDO::FETCH_ASSOC);
        $columns = array_column($columns, null, 'name');
        $this->assertSame(0, (int) $columns['first_id']['notnull']);
        $this->assertSame(1, (int) $columns['unrelated']['notnull']);
        $this->assertSame(1, (int) $columns['second_id']['notnull']);
        $this->assertSame(0, (int) $columns['third_id']['notnull']);
        $this->assertSame(1, (int) $columns['code']['notnull']);
        $constraintOnly = new Blueprint('docs_foreign');
        $constraintOnly->foreign('first_id')->references('id')->on('users');
        $this->assertStringContainsString('FOREIGN KEY', $constraintOnly->compileCreate());
        query('users')->insert(['name' => 'Ada']);
        query('posts')->insert(['title' => 'Before migration', 'user_id' => 1]);
        Schema::table('posts', fn(Blueprint $table) => $table->softDeletes());
        $this->assertTrue(Schema::hasColumn('posts', 'deleted_at'));
        $this->assertNull(query('posts')->where('id', 1)->value('deleted_at'));
        $post = DatabaseFixturePost::create(['title' => 'Recover me', 'user_id' => 1, 'views' => 10]);
        $id = $post->primaryValue();
        $this->assertTrue($post->usesSoftDeletes());
        $this->assertSame('deleted_at', $post->getSoftDeleteColumn());
        $this->assertSame(2, DatabaseFixturePost::count());
        $exists = fn($q) => $q->table('posts')->selectRaw('1')->whereColumn('posts.user_id', 'users.id');
        $this->assertSame(1, query('users')->whereExists($exists)->count());
        $this->assertSame(0, query('users')->whereNotExists($exists)->count());
        $this->assertSame(1, query('users')->where('id', -1)->orWhereExists($exists)->count());
        $this->assertSame(1, query('users')->whereInSub('id', query('posts')->select('user_id'))->count());
        $this->assertSame(0, (int) query('users')->where('name', 'Ada')->selectSub(query('posts')->selectRaw('COUNT(*)')->where('title', 'absent'), 'matched')->fetchAssoc()->first()['matched']);
        $this->assertSame(1, (int) DatabaseFixtureUser::withExists('posts')->findOrFail(1)->posts_exists);
        $this->assertSame(0, (int) DatabaseFixtureUser::withExists(['posts as has_posts' => fn($q) => $q->where('title', 'absent')])->findOrFail(1)->has_posts);
        $this->assertSame(1, DatabaseFixturePost::whereKey(1)->as('p')->join('users', 'users.id', '=', 'p.user_id')->select('p.*')->firstOrFail()->primaryValue());
        $this->assertSame(0, DatabaseFixturePost::whereKey([])->count());
        $this->assertSame(1, DatabaseFixtureUser::findOrFail(1)->posts()->whereKey(1)->firstOrFail()->primaryValue());
        $locked = DB::transaction(fn() => DatabaseFixturePost::whereKey($id)->lockForUpdate()->firstOrFail());
        $this->assertSame($id, $locked->primaryValue());
        $this->assertFalse(str_contains(DatabaseFixturePost::whereKey($id)->sharedLock()->toSql(), 'FOR UPDATE'));

        $this->assertTrue($post->remove());
        $this->assertTrue($post->wasDeleted());
        $this->assertFalse($post->trashed()); // Deletion does not refresh loaded attributes.
        $this->assertFalse(DatabaseFixturePost::find($id));
        $this->assertSame(1, DatabaseFixturePost::count());
        $this->assertSame(2, DatabaseFixturePost::withTrashed()->count());
        $this->assertSame(1, DatabaseFixturePost::onlyTrashed()->count());
        $this->assertFalse(DatabaseFixturePost::where('id', $id)->exists());
        $this->assertSame(1, DatabaseFixturePost::onlyTrashed()->orderDesc('id')->paginate(20)->total());
        $this->assertSame(1, DatabaseFixturePost::orderDesc('id')->paginate(20)->total());
        $archived = DatabaseFixturePost::onlyTrashed()->findOrFail($id);
        $this->assertTrue($archived->trashed());
        $this->assertInstanceOf(\Spark\Carbon::class, $archived->deleted_at);
        $this->assertTrue($archived->restore());
        $this->assertFalse($archived->trashed());
        $this->assertSame(2, DatabaseFixturePost::count());
        $this->assertSame(1, DatabaseFixturePost::where('id', $id)->delete());
        $this->assertSame(1, DatabaseFixturePost::withTrashed()->where('id', $id)->delete());
        $this->assertFalse(DatabaseFixturePost::query()->restore());
        $this->assertSame(0, DatabaseFixturePost::query()->delete());
        $this->assertSame(0, DatabaseFixturePost::query()->forceDelete());
        $this->assertSame(0, DatabaseFixturePost::query()->update(['title' => 'Do not update all']));
        $this->assertSame(0, DatabaseFixturePost::where('id', $id)->update(['title' => 'Active only']));
        $this->assertSame(1, DatabaseFixturePost::onlyTrashed()->where('id', $id)->update(['title' => 'Still archived']));
        $this->assertTrue(DatabaseFixturePost::where('id', $id)->restore());
        $this->assertSame('Still archived', DatabaseFixturePost::findOrFail($id)->title);
        $this->assertTrue(DatabaseFixturePost::where('id', $id)->increment('views'));
        $this->assertSame(11, (int) DatabaseFixturePost::findOrFail($id)->views);
        $this->assertSame(1, DatabaseFixturePost::where('id', $id)->delete());

        // Related reads and relationship subqueries both use the model's prepared scope.
        $user = DatabaseFixtureUser::with('posts')->findOrFail(1);
        $this->assertCount(1, $user->posts);
        $user = DatabaseFixtureUser::with(['posts' => fn($q) => $q->withTrashed()])->findOrFail(1);
        $this->assertCount(2, $user->posts);
        $this->assertSame(1, (int) DatabaseFixtureUser::withCount('posts')->findOrFail(1)->posts_count);
        $user = DatabaseFixtureUser::has('posts')->withCount('posts')->findOrFail(1);
        $this->assertSame(1, (int) $user->posts_count);
        $this->assertSame(2, (int) DatabaseFixtureUser::withCount('posts as all_posts', fn($q) => $q->withTrashed())->findOrFail(1)->all_posts);
        $this->assertSame(1, DatabaseFixturePost::onlyTrashed()->forceDelete());
        $this->assertNull(DatabaseFixturePost::withTrashed()->find($id));
        $this->assertSame(1, DatabaseFixturePost::count());

        // The active row is protected by an explicit trash scope.
        $this->assertSame(0, DatabaseFixturePost::onlyTrashed()->where('id', 1)->forceDelete());
        $this->assertSame(1, DatabaseFixturePost::withoutTrashed()->count());

        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->string('title')->required();
            $table->timestamp('archived_at')->nullable();
        });
        $custom = DatabaseFixtureCustomArchive::create(['title' => 'Custom column']);
        $this->assertTrue($custom->remove());
        $this->assertSame(0, DatabaseFixtureCustomArchive::count());
        $custom = DatabaseFixtureCustomArchive::onlyTrashed()->firstOrFail();
        $this->assertTrue($custom->trashed());
        $this->assertInstanceOf(\Spark\Carbon::class, $custom->archived_at);
        $this->assertTrue($custom->restore());
        $this->assertSame(1, DatabaseFixtureCustomArchive::count());

        // Rollback the added column, after stopping use of the model scope.
        Schema::table('posts', fn(Blueprint $table) => $table->dropColumn('deleted_at'));
        $this->assertFalse(Schema::hasColumn('posts', 'deleted_at'));
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->required()->unique();
            $table->string('name')->required();
            $table->integer('price')->required();
        });
        query('products')->insert(['sku' => 'SPARK-01', 'name' => 'Old', 'price' => 10]);
        query('products')->upsert([
            ['sku' => 'SPARK-01', 'name' => 'Starter', 'price' => 25],
            ['sku' => 'SPARK-02', 'name' => 'Team', 'price' => 50],
        ], ['sku'], ['name', 'price']);
        $this->assertSame(2, query('products')->count());
        $this->assertSame('Starter', query('products')->where('sku', 'SPARK-01')->value('name'));
        $titles = query('products')->select(['name'])->orderAsc('id')
            ->addMapper(fn(array $rows) => array_map(fn($row) => $row->name, $rows))->all();
        $this->assertSame(['Starter', 'Team'], $titles);
        // New upsert arguments: explicit columns preserve other stored values.
        query('products')->upsert(
            ['sku' => 'SPARK-01', 'name' => 'Do not replace', 'price' => 30],
            conflict: ['sku'],
            update: ['price'],
        );
        $this->assertSame('Starter', query('products')->where('sku', 'SPARK-01')->value('name'));
        $this->assertSame(30, (int) query('products')->where('sku', 'SPARK-01')->value('price'));
        query('products')->upsert(['sku' => 'SPARK-01', 'name' => 'Starter', 'price' => 35], ['sku']);
        $this->assertSame(35, (int) query('products')->where('sku', 'SPARK-01')->value('price'));
        query('products')->upsert(['sku' => 'SPARK-01', 'name' => 'Starter', 'price' => 40], ['sku'], []);
        $this->assertSame(40, (int) query('products')->where('sku', 'SPARK-01')->value('price'));
        $productId = query('products')->where('sku', 'SPARK-01')->value('id');
        query('products')->upsert(['id' => $productId, 'sku' => 'SPARK-01', 'name' => 'Starter', 'price' => 45]);
        $this->assertSame(45, (int) query('products')->where('sku', 'SPARK-01')->value('price'));
        $this->assertSame(2, query('products')->count());
        try {
            DB::transaction(function () {
                DB::table('products')->where('sku', 'SPARK-01')->update(['name' => 'Rolled back']);
                throw new \RuntimeException('Rollback probe');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback probe', $error->getMessage());
        }
        $this->assertSame('Starter', query('products')->where('sku', 'SPARK-01')->value('name'));
        $this->assertSame('committed', DB::transaction(fn() => 'committed'));
        $this->checkRelationshipScopes();
    }
}
