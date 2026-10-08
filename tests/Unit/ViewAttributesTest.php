<?php

use Spark\Testing\TestCase;
use Spark\View\Attributes;

final class ViewAttributesTest extends TestCase
{
    public function test_values_escape_quotes_and_markup(): void
    {
        $attributes = new Attributes(['title' => '"><script>&']);
        $this->assertSame('title="&quot;&gt;&lt;script&gt;&amp;"', (string) $attributes);
    }

    public function test_boolean_and_null_attributes(): void
    {
        $attributes = new Attributes(['disabled' => true, 'hidden' => false, 'title' => null, 'data-count' => 0]);
        $this->assertSame('disabled data-count="0"', (string) $attributes);
    }

    public function test_merging_defaults_preserves_explicit_values_and_combines_classes(): void
    {
        $attributes = new Attributes(['class' => 'custom', 'type' => 'submit']);
        $merged = $attributes->merge(['class' => 'base', 'type' => 'button']);
        $this->assertSame('base custom', $merged->get('class'));
        $this->assertSame('submit', $merged->get('type'));
        $this->assertSame('custom', $attributes->get('class'));
    }

    public function test_prefix_filtering_and_exclusion_preserve_original(): void
    {
        $attributes = new Attributes(['wire:model' => 'name', 'wire:click' => 'save', 'class' => 'button']);
        $this->assertCount(2, $attributes->whereStartsWith('wire:')->toArray());
        $this->assertSame(['class' => 'button'], $attributes->except(['wire:model', 'wire:click'])->toArray());
        $this->assertCount(3, $attributes->toArray());
    }
}
