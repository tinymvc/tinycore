<?php

require_once dirname(__DIR__, 2) . '/Support/DatabaseScenarioTestCase.php';

final class PaginationBoundaryTest extends DatabaseScenarioTestCase
{
    public function test_second_page_has_matching_offset_and_total(): void
    {
        request()->query->put('page', 2);
        $page = ScenarioPost::orderBy('id')->paginate(2);
        $this->assertSame(5, $page->total());
        $this->assertSame(2, $page->page());
        $this->assertSame(2, $page->offset());
        $this->assertSame(['Gamma', 'Delta'], array_map(fn ($post) => $post->title, $page->items()));
    }

    public function test_out_of_range_page_matches_clamped_paginator_metadata(): void
    {
        request()->query->put('page', 999);
        $page = ScenarioPost::orderBy('id')->paginate(2);
        $this->assertSame(3, $page->page());
        $this->assertSame(['Orphan'], array_map(fn ($post) => $post->title, $page->items()));
    }

    public function test_zero_limit_uses_normalized_page_size(): void
    {
        $page = ScenarioPost::orderBy('id')->paginate(0);
        $this->assertSame(1, $page->limit());
        $this->assertCount(1, $page->items());
    }

    public function test_negative_limit_uses_normalized_page_size(): void
    {
        $page = ScenarioPost::orderBy('id')->paginate(-2);
        $this->assertSame(1, $page->limit());
        $this->assertCount(1, $page->items());
    }

    public function test_invalid_page_defaults_to_first_page(): void
    {
        request()->query->put('page', ['malformed']);
        $page = ScenarioPost::orderBy('id')->paginate(2);
        $this->assertSame(1, $page->page());
        $this->assertSame('Alpha', $page->items()[0]->title);
    }

    public function test_custom_page_keyword_ignores_default_page(): void
    {
        request()->query->put('page', 1);
        request()->query->put('posts_page', 2);
        $page = ScenarioPost::orderBy('id')->paginate(2, 'posts_page');
        $this->assertSame(2, $page->page());
        $this->assertSame('Gamma', $page->items()[0]->title);
    }

    public function test_empty_result_has_no_items_and_zero_total(): void
    {
        $page = ScenarioPost::whereKey(-1)->paginate(2);
        $this->assertSame(0, $page->total());
        $this->assertSame(0, $page->pages());
        $this->assertSame([], $page->items());
    }

    public function test_grouped_pagination_counts_groups(): void
    {
        $page = query('scenario_posts')->select('status')->groupBy('status')->orderBy('status')->paginate(1);
        $this->assertSame(2, $page->total());
        $this->assertCount(1, $page->items());
        $this->assertSame('draft', $page->items()[0]->status);
    }
}
