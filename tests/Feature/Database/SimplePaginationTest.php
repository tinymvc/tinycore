<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

use Spark\Http\Resources\JsonResource;
use Spark\Paginator\AbstractPaginator;
use Spark\Paginator\SimplePaginator;

final class SimplePaginationTest extends DatabaseScenarioTestCase
{
    public function test_one_query_uses_offset_and_lookahead_without_counting_or_mutating_the_builder(): void
    {
        $queries = [];
        event()->listen('app:db.queryExecuted', function ($query) use (&$queries) {
            $queries[] = $query;
        });
        $query = query('scenario_posts')->whereRaw('score >= ?', [0])->orderBy('id')->limit(1);
        $sql = $query->toSql();
        $page = $query->simplePaginate(2, page: 2);

        $this->assertInstanceOf(SimplePaginator::class, $page);
        $this->assertSame(['Gamma', 'Delta'], $page->pluck('title'));
        $this->assertCount(1, $queries);
        $this->assertFalse(str_contains(strtoupper($queries[0]), 'COUNT('));
        $this->assertTrue(str_contains(strtoupper($queries[0]), 'LIMIT 3 OFFSET 2'));
        $this->assertSame($sql, $query->toSql());
    }

    public function test_first_middle_last_and_exact_pages_have_consistent_navigation(): void
    {
        $query = ScenarioPost::orderBy('id');
        $first = $query->simplePaginate(2);
        $second = $query->simplePaginate(2, page: 2);
        $last = $query->simplePaginate(2, page: 3);

        $this->assertNull($first->previousPageUrl());
        $this->assertTrue($first->hasMorePages());
        $this->assertSame(2, $second->currentPage());
        $this->assertSame(3, $second->firstItem());
        $this->assertSame(4, $second->lastItem());
        $this->assertSame($first->url(1), $second->previousPageUrl());
        $this->assertSame(['Orphan'], $last->pluck('title'));
        $this->assertSame(5, $last->lastItem());
        $this->assertNull($last->nextPageUrl());
        $this->assertFalse($query->simplePaginate(5)->hasPages());
    }

    public function test_request_page_custom_name_and_invalid_bounds(): void
    {
        request()->query->put('page', 3);
        request()->query->put('posts', 2);
        $query = ScenarioPost::orderBy('id');
        $page = $query->simplePaginate(2, pageName: 'posts');
        $this->assertSame(['Gamma', 'Delta'], $page->pluck('title'));

        foreach ([['bad'], 'bad', 0, -1] as $value) {
            request()->query->put('page', $value);
            $this->assertSame(1, $query->simplePaginate(2)->currentPage());
        }
        foreach ([0, -2] as $size) {
            $page = $query->simplePaginate($size, page: 1);
            $this->assertSame(1, $page->perPage());
            $this->assertSame(1, count($page));
        }

        $empty = $query->simplePaginate(2, page: 999);
        $this->assertSame(999, $empty->currentPage());
        $this->assertTrue($empty->isEmpty());
        $this->assertNull($empty->toArray()['from']);
        $this->assertNull($empty->toArray()['to']);
        $this->assertNull($empty->nextPageUrl());
        $this->assertSame($empty->url(998), $empty->previousPageUrl());
    }

    public function test_scopes_casts_eager_loading_and_mappers_apply_only_to_returned_items(): void
    {
        ScenarioPost::find(2)->remove();
        $mapped = [];
        $page = ScenarioPost::with('user')->orderBy('id')
            ->addMapper(function ($items) use (&$mapped) {
                $mapped = array_map(fn ($post) => $post->id, $items);
                return $items;
            })
            ->simplePaginate(2);

        $this->assertSame([1, 3], $mapped);
        $this->assertSame(['Alpha', 'Gamma'], $page->pluck('title'));
        $this->assertTrue($page[0]->relationLoaded('user'));
        $this->assertSame('Ada', $page[0]->user->name);
        $this->assertSame(['shared' => true, 'tags' => ['php']], $page[0]->metadata);
        $this->assertTrue($page->hasMorePages());
    }

    public function test_resources_and_links_share_the_common_pagination_contract(): void
    {
        request()->query->put('filter', 'a&"b');
        $page = ScenarioPost::orderBy('id')->simplePaginate(2, pageName: 'posts');
        $data = JsonResource::collection($page)->additional(['meta' => ['version' => 1]])->responseData();
        $this->assertCount(2, $data['data']);
        $this->assertSame($page->nextPageUrl(), $data['links']['next']);
        $this->assertSame(1, $data['meta']['version']);
        $this->assertSame(2, $data['meta']['per_page']);
        $this->assertFalse(isset($data['meta']['total']));
        $this->assertFalse(isset($data['meta']['last_page']));
        $this->assertFalse(isset($data['links']['last']));
        parse_str(parse_url($page->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('a&"b', $query['filter']);
        $this->assertSame('2', $query['posts']);
        $this->assertTrue(str_contains((string) $page->links(), '&amp;posts='));
    }

    public function test_selected_columns_array_fetches_and_grouped_queries(): void
    {
        $page = query('scenario_posts')->select('id', 'title')->orderBy('id')->fetchAssoc()->simplePaginate(2);
        $this->assertSame(['id', 'title'], array_keys($page[0]));
        $page = query('scenario_posts')->orderBy('id')->simplePaginate(2, ['id', 'title'], page: 2);
        $this->assertSame(['Gamma', 'Delta'], $page->pluck('title'));

        $query = query('scenario_posts')->select('status')->groupBy('status')->orderBy('status');
        $first = $query->simplePaginate(1);
        $second = $query->simplePaginate(1, page: 2);
        $this->assertSame(['draft'], $first->pluck('status'));
        $this->assertTrue($first->hasMorePages());
        $this->assertSame(['published'], $second->pluck('status'));
        $this->assertFalse($second->hasMorePages());
    }

    public function test_all_paginators_share_collection_serialization_and_independent_copies(): void
    {
        foreach (['paginate', 'simplePaginate', 'cursorPaginate'] as $method) {
            $page = ScenarioPost::orderBy('id')->$method(2);
            $this->assertInstanceOf(AbstractPaginator::class, $page);
            $this->assertSame($page->items(), iterator_to_array($page));
            $this->assertSame(2, count($page));
            $keys = [];
            $this->assertSame($page, $page->map(function ($post, $key) use (&$keys) {
                $keys[] = $key;
                return ['id' => $post->id];
            }));
            $this->assertSame([0, 1], $keys);
            $this->assertSame([['id' => 1], ['id' => 2]], json_decode($page->toJson(), true)['data']);
            $copy = $page->copy();
            $copy[] = ['id' => 3];
            unset($copy[0]);
            $this->assertSame([['id' => 1], ['id' => 2]], $page->items());
            $this->assertSame(3, $copy[2]['id']);
            $page->getCollection()->push(['id' => 4]);
            $this->assertSame(3, count($page));
        }
    }
}
