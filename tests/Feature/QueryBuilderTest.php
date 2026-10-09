<?php

require_once dirname(__DIR__) . '/Support/DatabaseTestCase.php';

use Spark\Database\Schema\Schema;
use Spark\Database\Schema\Blueprint;

final class QueryBuilderTest extends DatabaseTestCase
{
    public function test_relationship_exists_projection_sql(): void
    {
        $database = $this->app->get(\Spark\Database\DB::class);
        try {
            // Compile every relationship shape with each grammar, without connecting
            // to MySQL/PostgreSQL. SQLite can silently accept a double-quoted "1".
            foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
                $this->app->instance(\Spark\Database\DB::class, new \Spark\Database\DB(['driver' => $driver]));
                foreach ([
                    [DatabaseFixtureRelationUser::class, 'posts'],
                    [DatabaseFixtureRelationUser::class, 'firstPost'],
                    [DatabaseFixtureRelationPost::class, 'user'],
                    [DatabaseFixtureRelationUser::class, 'roles'],
                    [DatabaseFixtureRelationCountry::class, 'posts'],
                    [DatabaseFixtureRelationUser::class, 'posts.comments'],
                ] as [$model, $relation]) {
                    $sql = $model::query()->as('parent')->withExists("$relation as viewer_exists")->toSql();
                    $this->assertStringContainsString('EXISTS(SELECT 1 FROM ', $sql);
                    $this->assertFalse((bool) preg_match('/SELECT\s+[`"\[]1[`"\]]/', $sql));
                }
                $sql = DatabaseFixtureRelationUser::withExists('posts as viewer_liked', fn($q) => $q->where('views', 10))
                    ->withExists('posts as viewer_saved', fn($q) => $q->where('views', 20))
                    ->withCount('posts')->withSum('posts', 'views')->toSql();
                $this->assertSame(2, substr_count($sql, 'EXISTS(SELECT 1 FROM '));
                $this->assertStringContainsString('SELECT COUNT(*) FROM ', $sql);
                $this->assertStringContainsString('SELECT SUM(', $sql);
            }
        } finally {
            $this->app->instance(\Spark\Database\DB::class, $database);
        }
    }

    public function test_column_qualification_for_ordering(): void
    {
        Schema::create('query_owners', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->integer('score');
        });
        Schema::create('query_keys', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->integer('owner_id');
            $table->integer('score');
            $table->softDeletes();
        });
        query('query_owners')->insert(['id' => 1, 'code' => 'owner-a', 'score' => 100]);
        query('query_owners')->insert(['id' => 2, 'code' => 'owner-b', 'score' => 1]);
        foreach ([['a', 1, 10, null], ['b', 1, 20, null], ['c', 2, 30, null], ['d', 1, 40, '2026-01-01']] as [$code, $owner, $score, $deleted]) {
            query('query_keys')->insert(['code' => $code, 'owner_id' => $owner, 'score' => $score, 'deleted_at' => $deleted]);
        }
        $joined = fn() => DatabaseFixtureQueryKey:: as('k')->join('query_owners', 'query_owners.id', '=', 'k.owner_id')->select('k.*');
        foreach (['score', ' score ', 'k.score', ' k.score ', '"score"', '`score`', '[score]', '"k"."score"', '`k`.`score`', '[k].[score]', 'ABS(k.score)'] as $field) {
            $this->assertSame('c', $joined()->latest($field)->firstOrFail()->code);
            $this->assertSame('a', $joined()->oldest($field)->firstOrFail()->code);
        }
        // An explicitly qualified joined-table column must not acquire the base alias.
        $this->assertSame(1, $joined()->latest('query_owners.score')->firstOrFail()->owner_id);
        $this->assertSame('c', $joined()->oldest('query_owners.score')->firstOrFail()->code);
        $this->assertSame('a', $joined()->orderAsc()->firstOrFail()->code);
        $this->assertSame('c', $joined()->orderDesc()->firstOrFail()->code);
        $this->assertSame('c', $joined()->last()->code);
        $this->assertSame('b', $joined()->whereKey('b')->latest('k.score')->firstOrFail()->code);
        $this->assertSame('b', $joined()->whereNotKey('c')->latest('k.score')->firstOrFail()->code);
        $this->assertSame('b', DatabaseFixtureQueryOwner::findOrFail(1)->records()->latest('query_keys.score')->firstOrFail()->code);
        $this->assertSame('a', DatabaseFixtureQueryOwner::findOrFail(1)->records()->oldest('query_keys.score')->firstOrFail()->code);
        $subquery = DatabaseFixtureQueryOwner::findOrFail(1)->records()->select('query_keys.code')->latest('query_keys.score');
        $this->assertSame(2, DatabaseFixtureQueryKey::whereIn('code', $subquery)->count());
        $subquery = DatabaseFixtureQueryKey:: as('inner_key')->whereKey('b')->select('inner_key.code')->latest('inner_key.score');
        $this->assertSame('b', DatabaseFixtureQueryKey::whereIn('code', $subquery)->firstOrFail()->code);
        $this->assertSame(20, (int) DatabaseFixtureQueryOwner::withMax('records', 'query_keys.score')->findOrFail(1)->records_max);

        // Defaults must follow FROM replacements and prefixes, not the original table.
        Schema::create('audit_rows', function (Blueprint $table) {
            $table->id();
            $table->integer('score');
        });
        query('audit_rows')->insert(['score' => 10]);
        query('audit_rows')->insert(['score' => 20]);
        foreach ([fn() => query('rows')->prefix('audit_'), fn() => query('unused')->from('rows')->prefix('audit_'), fn() => query('unused')->from('audit_rows')] as $source) {
            $this->assertSame('audit_rows.score', $source()->withAlias('score'));
            $this->assertSame(2, (int) $source()->latest('score')->fetchAssoc()->first()['id']);
            $this->assertSame(1, (int) $source()->oldest('score')->fetchAssoc()->first()['id']);
            $this->assertSame(2, (int) $source()->orderDesc()->fetchAssoc()->first()['id']);
            $this->assertSame(1, (int) $source()->orderAsc()->fetchAssoc()->first()['id']);
        }
        $aliased = query('unused')->from('rows', 'r')->prefix('audit_');
        $this->assertSame('r.score', $aliased->withAlias('score'));
        $this->assertSame('other.score', $aliased->withAlias('other.score'));
        $this->assertSame('r.*', $aliased->withAlias('*'));
        $this->assertSame('MAX(r.score)', $aliased->withAlias('MAX(r.score)'));
        $this->assertSame('-score', $aliased->withAlias('-score'));
        $this->assertSame('score+1', $aliased->withAlias('score+1'));
        $this->assertSame(1, (int) query('audit_rows')->latest('-score')->fetchAssoc()->first()['id']);
        $this->assertSame('score AS chosen_score', $aliased->withAlias('score AS chosen_score'));
        $this->assertSame(2, (int) $aliased->latest('r.score')->fetchAssoc()->first()['id']);

        // Compilation only: no external MySQL/PostgreSQL connection is opened.
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $q = new \Spark\Database\QueryBuilder(new \Spark\Database\DB(['driver' => $driver]));
            $q->table('unused')->from('rows', 'r')->prefix('audit_');
            $this->assertStringContainsString('ORDER BY r.score DESC', $q->latest('r.score')->toSql());
            $this->assertSame('r."score"', $q->withAlias('"score"'));
            $this->assertSame('r.`a``b`', $q->withAlias('`a``b`'));
            $this->assertSame('r.[a]]b]', $q->withAlias('[a]]b]'));
            $this->assertSame('r."a""b"', $q->withAlias('"a""b"'));
        }
    }

    public function test_key_exclusions_and_subquery_predicates(): void
    {
        Schema::create('query_owners', function (Blueprint $table) {
            $table->id();
            $table->string('code');
        });
        Schema::create('query_keys', function (Blueprint $table) {
            $table->string('code')->primary()->required();
            $table->integer('owner_id')->required();
            $table->integer('score')->required();
            $table->softDeletes();
        });
        query('query_owners')->insert(['id' => 1, 'code' => 'owner-a']);
        query('query_owners')->insert(['id' => 2, 'code' => 'owner-b']);
        foreach ([['a', 1, 1, null], ['b', 1, 1, null], ['c', 2, 2, null], ['d', 1, 2, '2026-01-01'], ['0', 1, 2, null]] as [$code, $owner, $score, $deleted]) {
            query('query_keys')->insert(['code' => $code, 'owner_id' => $owner, 'score' => $score, 'deleted_at' => $deleted]);
        }
        // Scalar, sparse-array, empty-array, zero and custom-key cases.
        $this->assertSame(3, DatabaseFixtureQueryKey::whereNotKey('a')->count());
        $this->assertSame(2, DatabaseFixtureQueryKey::whereNotKey([4 => 'a', 9 => 'b'])->count());
        $this->assertSame(4, DatabaseFixtureQueryKey::whereNotKey([])->count());
        $this->assertSame(0, DatabaseFixtureQueryKey::whereKey([])->count());
        $this->assertSame(0, DatabaseFixtureQueryKey::whereNotKey('0')->whereKey('0')->count());
        $this->assertSame(3, DatabaseFixtureQueryKey::whereNotKey(0)->count());
        $this->assertSame(0, DatabaseFixtureQueryKey::whereNotKey(['a', 'b', 'c', '0'])->count());
        $this->assertSame(1, DatabaseFixtureQueryKey::withTrashed()->whereNotKey(['a', 'b', 'c', '0'])->count());
        $this->assertSame('b', DatabaseFixtureQueryKey::whereNotKey('a')->whereNotKey('c')->whereKey('b')->firstOrFail()->code);
        $this->assertSame('b', DatabaseFixtureQueryKey::whereNotKey(['a', 'c', '0'])->as('k')
            ->join('query_owners', 'query_owners.id', '=', 'k.owner_id')->select('k.*')->firstOrFail()->code);
        $this->assertSame(1, DatabaseFixtureQueryOwner::whereNotKey(2)->whereNotKey([])->count());
        $owner = DatabaseFixtureQueryOwner::findOrFail(1);
        $this->assertSame(1, $owner->records()->whereNotKey(['a', '0'])->count());
        $this->assertNull($owner->records()->whereNotKey([])->find('c'));
        $this->assertSame(3, $owner->records()->whereNotKey([])->count());

        // Every IN/NOT IN and OR form accepts the same four input types.
        $sources = [
            fn() => [5 => 'a', 10 => 'b'],
            fn() => "SELECT code FROM query_keys WHERE score = 1",
            fn() => query('query_keys')->select('code')->where('score', 1),
            fn() => function ($sub) {
                $sub->table('query_keys')->select('code')->where('score', 1);
            },
        ];
        foreach ($sources as $source) {
            $this->assertSame(2, DatabaseFixtureQueryKey::whereIn('code', $source())->count());
            $this->assertSame(2, DatabaseFixtureQueryKey::whereNotIn('code', $source())->count());
            $this->assertSame(2, DatabaseFixtureQueryKey::where('code', 'missing')->orWhereIn('code', $source())->count());
            $this->assertSame(2, DatabaseFixtureQueryKey::where('code', 'missing')->orWhereNotIn('code', $source())->count());
            $this->assertSame(3, DatabaseFixtureQueryKey::whereKey('c')->orWhereIn('code', $source())->count());
        }
        $this->assertSame(0, DatabaseFixtureQueryKey::whereIn('code', [])->count());
        $this->assertSame(4, DatabaseFixtureQueryKey::whereNotIn('code', [])->count());
        $this->assertSame(0, DatabaseFixtureQueryKey::where('code', 'missing')->orWhereIn('code', [])->count());
        $this->assertSame(4, DatabaseFixtureQueryKey::where('code', 'missing')->orWhereNotIn('code', [])->count());
        // Matching placeholder names in independently built queries must not collide.
        $inner = query('query_keys')->select('code')->where('score', 1);
        $this->assertSame(0, DatabaseFixtureQueryKey::where('score', 2)->whereIn('code', $inner)->count());
        $this->assertSame(1, DatabaseFixtureQueryKey::whereIn('code', DatabaseFixtureQueryKey::whereNotKey(['a', 'c', '0'])->select('code'))->count());
        $this->assertSame(2, DatabaseFixtureQueryKey::whereIn('code', $inner)->whereIn('code', $inner)->count());

        // EXISTS has only a subquery argument; correlate explicitly, never implicitly.
        $existenceSources = [
            fn() => "SELECT 1 FROM query_keys WHERE query_keys.owner_id = query_owners.id AND score = 1",
            fn() => query('query_keys')->selectRaw('1')->whereColumn('query_keys.owner_id', 'query_owners.id')->where('score', 1),
            fn() => function ($sub) {
                $sub->table('query_keys')->selectRaw('1')->whereColumn('query_keys.owner_id', 'query_owners.id')->where('score', 1);
            },
        ];
        foreach ($existenceSources as $source) {
            $this->assertSame(1, DatabaseFixtureQueryOwner::whereExists($source())->firstOrFail()->id);
            $this->assertSame(2, DatabaseFixtureQueryOwner::whereNotExists($source())->firstOrFail()->id);
            $this->assertSame(1, DatabaseFixtureQueryOwner::where('id', -1)->orWhereExists($source())->firstOrFail()->id);
            $this->assertSame(2, DatabaseFixtureQueryOwner::where('id', -1)->orWhereNotExists($source())->firstOrFail()->id);
            $this->assertSame(2, DatabaseFixtureQueryOwner::whereExists(subquery: $source(), not: true)->firstOrFail()->id);
            $this->assertSame(2, DatabaseFixtureQueryOwner::where('id', 1)->whereNotExists($source(), boolean: 'OR')->count());
        }
        // Exclusion writes must use physical table qualifiers even after aliasing.
        $this->assertSame(1, DatabaseFixtureQueryKey::whereNotKey(['a', 'c', '0'])->as('k')->update(['score' => 8]));
        $this->assertSame(8, (int) DatabaseFixtureQueryKey::findOrFail('b')->score);
        $this->assertSame(1, (int) DatabaseFixtureQueryKey::findOrFail('a')->score);
        $this->assertSame(2, (int) DatabaseFixtureQueryKey::withTrashed()->findOrFail('d')->score);
        $this->assertSame(1, $owner->records()->whereNotKey(['a', '0'])->delete());
        $this->assertFalse(DatabaseFixtureQueryKey::find('b'));
        $this->assertSame('c', DatabaseFixtureQueryKey::findOrFail('c')->code);
        $this->assertSame(1, DatabaseFixtureQueryKey::onlyTrashed()->whereNotKey('d')->forceDelete());
        $this->assertSame(1, DatabaseFixtureQueryKey::onlyTrashed()->count());
    }
}
