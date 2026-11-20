<?php

namespace Tests\Unit;

use Spark\Testing\TestCase;
use Spark\Utils\Highlight;

final class HighlightTest extends TestCase
{
    private string $fixtureFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureFile = dir_path(dirname(__DIR__) . '/Fixtures/sample_highlight_target.php');
        $code = <<<'PHP'
<?php

namespace App\Fixtures;

/**
 * Multi-line docblock comment
 * explaining what this class does.
 */
class SampleClass
{
    public function calculate(int $a, int $b): int
    {
        $sum = $a + $b;
        return $sum;
    }
}
PHP;
        @file_put_contents($this->fixtureFile, $code);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->fixtureFile)) {
            @unlink($this->fixtureFile);
        }
        parent::tearDown();
    }

    public function test_highlight_file_sets_active_class_on_target_line(): void
    {
        $html = Highlight::file($this->fixtureFile, 15, 10);

        $this->assertStringContainsString('class="syntax-highlight-line active" data-line="15"', $html);
        $this->assertStringContainsString('data-line="14"', $html);
        $this->assertStringNotContainsString('class="syntax-highlight-line active" data-line="14"', $html);
    }

    public function test_highlight_file_preserves_multiline_docblock_tag_balance(): void
    {
        // Lines 5-8 are docblock comment
        $html = Highlight::file($this->fixtureFile, 6, 8);

        $this->assertStringContainsString('Multi-line docblock comment', $html);
        $this->assertStringContainsString('explaining what this class does.', $html);

        // Every opened span should have matching closing span
        $opens = substr_count($html, '<span');
        $closes = substr_count($html, '</span>');
        $this->assertSame($opens, $closes);
    }

    public function test_highlight_file_maps_colors_to_dark_theme_tokens(): void
    {
        $html = Highlight::file($this->fixtureFile, 15, 10);

        // Ensure modern CSS variables with dark fallbacks are used
        $this->assertStringContainsString('var(--hl-', $html);
        $this->assertStringNotContainsString('color: #0000BB', $html);
        $this->assertStringNotContainsString('color: #007700', $html);
    }

    public function test_highlight_file_handles_non_existent_file(): void
    {
        $html = Highlight::file('non_existent_file_path_12345.php', 10, 10);

        $this->assertStringContainsString('syntax-highlight-empty', $html);
        $this->assertStringContainsString('File not found', $html);
    }

    public function test_highlight_file_handles_line_boundaries(): void
    {
        // Line 1
        $htmlFirst = Highlight::file($this->fixtureFile, 1, 6);
        $this->assertStringContainsString('class="syntax-highlight-line active" data-line="1"', $htmlFirst);

        // Line beyond end of file
        $htmlBeyond = Highlight::file($this->fixtureFile, 999, 6);
        $this->assertStringContainsString('syntax-highlight-line', $htmlBeyond);
    }

    public function test_value_to_html_formats_primitives_arrays_and_objects(): void
    {
        $this->assertSame('<span class="sf-null">null</span>', Highlight::valueToHtml(null));
        $this->assertSame('<span class="sf-bool">true</span>', Highlight::valueToHtml(true));
        $this->assertSame('<span class="sf-num">123</span>', Highlight::valueToHtml(123));
        $this->assertStringContainsString('"hello"', Highlight::valueToHtml('hello'));

        $arrHtml = Highlight::valueToHtml(['a' => 1, 'b' => 'test']);
        $this->assertStringContainsString('array:2', $arrHtml);
        $this->assertStringContainsString('sf-details', $arrHtml);

        $objHtml = Highlight::valueToHtml((object) ['prop' => 'val']);
        $this->assertStringContainsString('stdClass', $objHtml);
        $this->assertStringContainsString('prop', $objHtml);
    }

    public function test_render_html_dump_outputs_interactive_card(): void
    {
        $rendered = Highlight::renderHtmlDump([['foo' => 'bar']], '@/app/Test.php', '/var/www/app/Test.php', 10, false);

        $this->assertStringContainsString('// Dumped from:', $rendered);
        $this->assertStringContainsString('@/app/Test.php', $rendered);
        $this->assertStringContainsString(':10', $rendered);
        $this->assertStringContainsString('<pre', $rendered);
        $this->assertStringContainsString('foo', $rendered);
    }

    public function test_variables_returns_dump_entries(): void
    {
        $html = Highlight::variables('hello', 42);
        $this->assertStringContainsString('spark-dump-entry', $html);
        $this->assertStringContainsString('"hello"', $html);
        $this->assertStringContainsString('42', $html);
    }
}
