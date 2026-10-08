<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class BladeTest extends FrameworkTestCase
{
    public function test_blade_and_utility_examples(): void
    {
        $views = $this->storagePath . '/views';
        mkdir($views . '/components', 0700, true);
        config(['app.views_dir' => $views]);
        file_put_contents(
            $views . '/components/notice.blade.php',
            '@props(["tone" => "info"])<div {{ $attributes->merge(["class" => "notice"]) }}><strong>{{ $tone }}</strong>{!! $slot !!}</div>'
        );
        file_put_contents(
            $views . '/example.blade.php',
            '<h1>{{ $name }}</h1><x-notice tone="success"><p>Your changes have been saved.</p></x-notice>'
        );
        $rendered = blade()->render('example', ['name' => '<Ada>']);
        $this->assertStringContainsString('&lt;Ada&gt;', $rendered);
        $this->assertStringContainsString('<strong>success</strong>', $rendered);
        $hash = new \Spark\Hash('docs-smoke-private-test-key-32-characters');
        $encrypted = $hash->encryptString('private value');
        $this->assertSame('private value', $hash->decryptString($encrypted));
        $this->assertSame(64, strlen($hash->random(32)));
        $start = carbon('2026-01-10 12:00:00', 'UTC');
        $end = $start->addHours(25);
        $this->assertSame(1, $start->diffInDays($end));
        $this->assertSame(1, $end->diffInDays($start));
        $numbers = new \Spark\Support\LazyCollection(function () {
            for ($i = 1; $i <= 1000; $i++)
                yield $i;
        });
        $this->assertSame(250500, $numbers->filter(fn ($number) => $number % 2 === 0)->sum());
        $this->assertSame(
            'https://example.com/posts?page=2#results',
            (string) (new \Spark\Url('https://example.com/posts'))->withQuery(['page' => 2])->withFragment('results')
        );
        $path = $this->storagePath . '/report.txt';
        fm()->putAtomic($path, 'Report');
        $this->assertSame('Report', fm()->get($path));
        $response = new \Spark\Http\Client\HttpResponse(body: '', status: 201, lastUrl: '', length: 0);
        $this->assertTrue($response->ok());
        $transportFailure = new \Spark\Http\Client\HttpResponse(body: '', status: 0, lastUrl: '', length: 0);
        $this->assertFalse($transportFailure->isSuccessful());
        $this->assertTrue($transportFailure->failed());
    }
}
