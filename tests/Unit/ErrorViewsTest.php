<?php

namespace Tests\Unit;

use Spark\Facades\Blade;
use Spark\Http\Response;
use Tests\Support\LifecycleTestCase;

final class ErrorViewsTest extends LifecycleTestCase
{
    public function test_response_error_renders_black_next_style_page(): void
    {
        $response = Response::error(404, 'This page could not be found.');

        $content = $response->getContent();
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('background-color: #000000', $content);
        $this->assertStringContainsString('id="error-page"', $content);
        $this->assertStringContainsString('id="error-code">404</p>', $content);
        $this->assertStringContainsString('This page could not be found.', $content);
    }

    public function test_response_error_403_and_500_render_black_design(): void
    {
        $response403 = Response::error(403, 'Forbidden');
        $this->assertStringContainsString('background-color: #000000', $response403->getContent());
        $this->assertStringContainsString('id="error-code">403</p>', $response403->getContent());

        $response500 = Response::error(500, 'Internal Server Error');
        $this->assertStringContainsString('background-color: #000000', $response500->getContent());
        $this->assertStringContainsString('id="error-code">500</p>', $response500->getContent());
    }

    public function test_tracer_blade_view_renders_dark_interactive_debug_overlay(): void
    {
        $viewsPath = dirname(__DIR__, 2) . '/src/Foundation/resources/views';
        Blade::setPath($viewsPath);

        $html = Blade::render('tracer', [
            'type' => 'ErrorException',
            'message' => 'Undefined array key "user"',
            'file' => __FILE__,
            'line' => 10,
            'trace' => [
                [
                    'file' => __FILE__,
                    'line' => 10,
                    'function' => 'test_tracer_blade_view_renders_dark_interactive_debug_overlay',
                    'class' => self::class,
                    'type' => '->',
                    'args' => []
                ],
                [
                    'file' => dirname(__DIR__, 2) . '/vendor/phpunit/phpunit/src/Framework/TestCase.php',
                    'line' => 120,
                    'function' => 'runBare',
                    'class' => 'PHPUnit\\Framework\\TestCase',
                    'type' => '->',
                    'args' => []
                ]
            ]
        ]);

        // Theme and Branding
        $this->assertStringContainsString('--bg: #0a0a0a', $html);
        $this->assertStringContainsString('color-scheme: dark', $html);
        $this->assertStringNotContainsString('toggleTheme', $html);
        $this->assertStringNotContainsString('data-theme="light"', $html);
        $this->assertStringContainsString('Spark', $html);
        $this->assertStringContainsString('ErrorException', $html);
        $this->assertStringContainsString('Undefined array key &quot;user&quot;', $html);

        // Actions
        $this->assertStringContainsString('Copy', $html);
        $this->assertStringContainsString('data-copy=', $html);

        // Frames & Filter
        $this->assertStringContainsString('Call stack', $html);
        $this->assertStringContainsString('vendorToggle', $html);
        $this->assertStringContainsString('toggleVendor()', $html);

        // Highlight.php integration
        $this->assertStringContainsString('syntax-highlight-line active', $html);
        $this->assertStringContainsString('data-line="10"', $html);

        // Diagnostics
        $this->assertStringContainsString('tab-request', $html);
        $this->assertStringContainsString('tab-headers', $html);
        $this->assertStringContainsString('tab-env', $html);
    }
}
