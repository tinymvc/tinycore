<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

use Spark\Database\Exceptions\QueryBuilderException;
use Spark\Http\Resources\JsonResource;
use Spark\Paginator\Cursor;
use Spark\Paginator\CursorPaginator;

final class CursorPaginationTest extends DatabaseScenarioTestCase
{
    private function titles(CursorPaginator $page): array
    {
        return array_map(fn($post) => $post->title, $page->items());
    }

    public function test_forward_and_backward_pages_keep_the_requested_order(): void
    {
        foreach (['ASC', 'DESC'] as $direction) {
            $query = ScenarioPost::orderBy('id', $direction);
            $expected = $direction === 'ASC' ? ['Alpha', 'Beta', 'Gamma', 'Delta', 'Orphan'] : ['Orphan', 'Delta', 'Gamma', 'Beta', 'Alpha'];
            $first = $query->cursorPaginate(2);
            $second = $query->cursorPaginate(2, cursor: $first->nextCursor()->encode());
            $third = $query->cursorPaginate(2, cursor: $second->nextCursor());

            $this->assertSame(array_slice($expected, 0, 2), $this->titles($first));
            $this->assertSame(array_slice($expected, 2, 2), $this->titles($second));
            $this->assertSame(array_slice($expected, 4), $this->titles($third));
            $this->assertNull($first->previousCursor());
            $this->assertNull($third->nextCursor());
            $back = $query->cursorPaginate(2, cursor: $third->previousCursor());
            $this->assertSame($this->titles($second), $this->titles($back));
            $back = $query->cursorPaginate(2, cursor: $back->previousCursor());
            $this->assertSame($this->titles($first), $this->titles($back));
            $this->assertTrue($back->onFirstPage());
        }
    }

    public function test_mixed_compound_ordering_handles_ties_and_preserves_or_filters(): void
    {
        $query = ScenarioPost::where('status', 'draft')->orWhere('score', '>=', 20)
            ->orderBy('status')->orderBy('id', 'DESC');
        $first = $query->cursorPaginate(2);
        $second = $query->cursorPaginate(2, cursor: $first->nextCursor());
        $this->assertSame(['Delta', 'Alpha'], $this->titles($first));
        $this->assertSame(['Orphan', 'Gamma'], $this->titles($second));
        $this->assertFalse($second->hasMorePages());
        $this->assertSame($this->titles($first), $this->titles($query->cursorPaginate(2, cursor: $second->previousCursor())));

        $cursor = null;
        $titles = [];
        do {
            $page = $query->cursorPaginate(1, cursor: $cursor);
            $titles = [...$titles, ...$this->titles($page)];
            $cursor = $page->nextCursor();
        } while ($cursor !== null);
        $this->assertSame(['Delta', 'Alpha', 'Orphan', 'Gamma'], $titles);
    }

    public function test_cursor_reads_use_one_select_without_count_or_offset_and_keep_builder_reusable(): void
    {
        $queries = [];
        event()->listen('app:db.queryExecuted', function ($query) use (&$queries) {
            $queries[] = $query;
        });
        $query = query('scenario_posts')->whereRaw('score >= ?', [0])->orderBy('id')->offset(4);
        $original = $query->toSql();
        $page = $query->cursorPaginate(2, cursor: new Cursor(['id' => 2]));
        $this->assertSame(['Gamma', 'Delta'], $this->titles($page));
        $this->assertCount(1, $queries);
        $this->assertFalse(str_contains(strtoupper($queries[0]), 'COUNT('));
        $this->assertFalse(str_contains(strtoupper($queries[0]), 'OFFSET'));
        $this->assertSame($original, $query->toSql());
    }

    public function test_model_casts_eager_loading_and_soft_delete_scope_survive_pagination(): void
    {
        ScenarioPost::find(3)->remove();
        $query = CursorDatedPost::with('user')->whereNotNull('published_at')->orderBy('published_at')->orderBy('id');
        $first = $query->cursorPaginate(1);
        $this->assertInstanceOf(ScenarioPost::class, $first->items()[0]);
        $this->assertInstanceOf(\Spark\Carbon::class, $first->items()[0]->published_at);
        $this->assertTrue($first->items()[0]->relationLoaded('user'));
        $this->assertSame('2025-01-15 12:00:00', $first->nextCursor()->parameter('published_at'));
        $this->assertSame(['Orphan'], $this->titles($query->cursorPaginate(1, cursor: $first->nextCursor())));
    }

    public function test_qualified_columns_array_rows_and_selected_columns(): void
    {
        $query = query('scenario_posts', 'p')->select('p.id', 'p.title')->orderBy('p.id')->fetchAssoc();
        $first = $query->cursorPaginate(2);
        $second = $query->cursorPaginate(2, cursor: $first->nextCursor());
        $this->assertSame(['id', 'title'], array_keys($first->items()[0]));
        $this->assertSame(['Gamma', 'Delta'], array_column($second->items(), 'title'));
        $this->assertSame(2, $first->nextCursor()->parameter('p.id'));
        $page = query('scenario_posts')->orderBy('id')->cursorPaginate(1, ['id', 'title']);
        $this->assertSame(['id', 'title'], array_keys((array) $page->items()[0]));
    }

    public function test_request_cursor_custom_name_links_and_resource_metadata(): void
    {
        request()->query->put('search', 'a&"b');
        $first = ScenarioPost::orderBy('id')->cursorPaginate(2, cursorName: 'posts');
        request()->query->put('posts', $first->nextCursor()->encode());
        $second = ScenarioPost::orderBy('id')->cursorPaginate(2, cursorName: 'posts');
        $this->assertSame(['Gamma', 'Delta'], $this->titles($second));
        parse_str(parse_url($second->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('a&"b', $query['search']);
        $this->assertSame($second->nextCursor()->encode(), $query['posts']);
        $this->assertTrue(str_contains((string) $second->links(), '&amp;posts='));
        $data = CursorPostResource::collection($second)->additional(['meta' => ['version' => 1]])->responseData();
        $this->assertSame([['title' => 'Gamma'], ['title' => 'Delta']], $data['data']);
        $this->assertSame($second->nextPageUrl(), $data['links']['next']);
        $this->assertSame($second->previousCursor()->encode(), $data['meta']['prev_cursor']);
        $this->assertSame(1, $data['meta']['version']);
        $this->assertFalse(isset($data['meta']['total']));
        $this->assertSame($data, CursorPostResource::collection($second)->additional(['meta' => ['version' => 1]])->responseData());
    }

    public function test_empty_exact_and_invalid_page_sizes_and_cursors(): void
    {
        $empty = ScenarioPost::whereKey(-1)->orderBy('id')->cursorPaginate(2);
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->hasPages());
        $this->assertSame([], iterator_to_array($empty));
        $this->assertSame(0, count($empty));
        $exact = ScenarioPost::orderBy('id')->cursorPaginate(5);
        $this->assertFalse($exact->hasMorePages());

        foreach ([0, -2] as $size) {
            $page = ScenarioPost::orderBy('id')->cursorPaginate($size);
            $this->assertSame(1, $page->perPage());
            $this->assertCount(1, $page->items());
        }

        foreach (['not-a-cursor', ['bad'], base64_encode('{"id":[],"_pointsToNextItems":true}')] as $cursor) {
            request()->query->put('cursor', $cursor);
            $page = ScenarioPost::orderBy('id')->cursorPaginate(2);
            $this->assertSame(['Alpha', 'Beta'], $this->titles($page));
        }
    }

    public function test_unsupported_queries_fail_clearly(): void
    {
        foreach ([
            fn() => ScenarioPost::cursorPaginate(),
            fn() => ScenarioPost::random()->cursorPaginate(),
            fn() => query('scenario_posts')->groupBy('status')->orderBy('status')->cursorPaginate(),
            fn() => query('scenario_posts')->select('id AS position')->orderBy('position')->cursorPaginate(),
        ] as $paginate) {
            $this->assertThrows(QueryBuilderException::class, $paginate);
        }

        foreach ([
            fn() => ScenarioPost::orderBy('published_at')->cursorPaginate(),
            fn() => ScenarioPost::orderBy('id')->cursorPaginate(columns: ['title']),
            fn() => ScenarioPost::orderBy('id')->cursorPaginate(cursor: new Cursor(['wrong' => 2])),
        ] as $paginate) {
            $this->assertThrows(InvalidArgumentException::class, $paginate);
        }
    }

    public function test_deleted_boundary_and_new_earlier_rows_do_not_shift_the_next_page(): void
    {
        $query = ScenarioPost::orderBy('id');
        $first = $query->cursorPaginate(2);
        ScenarioPost::find(2)->remove();
        query('scenario_posts')->insert(['id' => 0, 'title' => 'Earlier']);
        $this->assertSame(['Gamma', 'Delta'], $this->titles($query->cursorPaginate(2, cursor: $first->nextCursor())));
    }

    public function test_transforming_items_preserves_cursor_boundaries_and_json_serialization(): void
    {
        $page = ScenarioPost::orderBy('id')->cursorPaginate(2);
        $cursor = $page->nextCursor()->encode();
        $this->assertSame($page, $page->through(fn($post) => ['title' => $post->title]));
        $this->assertSame($cursor, $page->nextCursor()->encode());
        $this->assertSame([['title' => 'Alpha'], ['title' => 'Beta']], json_decode($page->toJson(), true)['data']);
        $this->assertSame($page->toArray(), json_decode(json_encode($page), true));
        $this->assertSame($cursor, Cursor::fromEncoded($cursor)->encode());
    }
}

class CursorPostResource extends JsonResource
{
    public function toArray(?\Spark\Http\Request $request = null): array
    {
        return ['title' => $this->title];
    }
}

class CursorDatedPost extends ScenarioPost
{
    protected array $casts = ['published_at' => 'datetime'];
}
